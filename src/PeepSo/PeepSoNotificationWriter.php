<?php
/**
 * PeepSoNotificationWriter — thin wrapper around PeepSo's official
 * notifications write API per the §I1 single-graph rule.
 *
 * BCC must NOT INSERT directly into peepso_notifications — PeepSo
 * owns the write path:
 *   - reads the recipient's per-type opt-out (`peepso_notifications`
 *     user meta) and silently skips when the recipient has muted the
 *     `<type>_notification` slug
 *   - reads the actor's block list (PeepSoBlockUsers) and skips the
 *     write when the recipient has blocked the actor
 *   - inserts the row with current_time('mysql')
 *   - fires PeepSoSSEEvents::trigger('get_notifications') so any open
 *     PeepSo client UI gets a real-time poke
 *   - fires `peepso_action_create_notification_after`
 *
 * This wrapper delegates to `PeepSoNotifications::add_notification()`
 * (the same method PeepSo's own modules call) and surfaces a typed
 * BCC error contract so callers can match on reason codes without
 * branching on raw PeepSo return values (false vs int).
 *
 * Sibling to PeepSoStatusWriter (status posts) and PeepSoReactionWriter
 * (reactions). All three live here in bcc-core because they are
 * cross-plugin concerns — bcc-trust calls them, but they are not
 * bcc-trust-specific.
 *
 * @package BCC\Core\PeepSo
 * @since V1 (2026-04, §I1 notifications)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoNotificationWriter
{
    /**
     * Write a notification row.
     *
     * Return shape:
     *   - ['ok' => true,  'notification_id' => int]      success
     *   - ['ok' => true,  'notification_id' => 0,
     *      'skipped' => 'opt_out'|'blocked']             PeepSo accepted but silently
     *                                                    suppressed the row (recipient
     *                                                    muted this type, or has the
     *                                                    sender blocked). NOT an error.
     *   - ['ok' => false, 'reason' => 'unavailable']     PeepSo deactivated
     *   - ['ok' => false, 'reason' => 'invalid_user']    sender or recipient id <= 0
     *   - ['ok' => false, 'reason' => 'persist_failed']  add_notification returned false
     *                                                    AND no opt-out / block detected
     *
     * Note: PeepSo's add_notification returns FALSE both on hard
     * persistence failure AND when the recipient muted the type. The
     * caller can't tell those apart from the return value alone. We
     * collapse them into ['ok' => true, skipped: ...] vs ['ok' => false,
     * reason: 'persist_failed'] using a side-channel check on the
     * peepso_notifications user-meta opt-out — wrong-but-cheap is
     * better than misclassifying a delivered notification as failed.
     *
     * @param int    $fromUserId   actor (the user who took the action)
     * @param int    $toUserId     recipient
     * @param string $message      pre-rendered headline (≤200 chars; PeepSo truncates)
     * @param string $type         notification type slug (see NotificationType)
     * @param int    $moduleId     BCC module id (use BCC_NOTIFICATION_MODULE_ID)
     * @param int    $externalId   click-target id (post_id, page_id, etc.)
     * @param int    $actId        peepso_activities id when the notification ties
     *                              to a feed item; 0 otherwise
     *
     * @return array{ok: true, notification_id: int}
     *       | array{ok: true, notification_id: 0, skipped: string}
     *       | array{ok: false, reason: string}
     */
    public static function addNotification(
        int $fromUserId,
        int $toUserId,
        string $message,
        string $type,
        int $moduleId,
        int $externalId = 0,
        int $actId = 0
    ): array {
        if ($fromUserId <= 0 || $toUserId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_user'];
        }

        // No-op when actor and recipient are the same user (rank-up
        // celebrations being the canonical example) UNLESS the type
        // explicitly allows it. For now every BCC type that targets
        // self is delivered as a normal notification — the user wants
        // the audit row in their inbox even if they triggered it.
        // (Self-suppression hook deliberately skipped — PeepSo handles
        // it itself for some types via internal block-self checks.)

        if (!class_exists('PeepSoNotifications')) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        $service = \PeepSoNotifications::get_instance();
        $result = $service->add_notification(
            $fromUserId,
            $toUserId,
            $message,
            $type,
            $moduleId,
            $externalId,
            $actId
        );

        if ($result === false || $result === null) {
            $skipReason = self::detectSkipReason($fromUserId, $toUserId, $type);
            if ($skipReason !== null) {
                return ['ok' => true, 'notification_id' => 0, 'skipped' => $skipReason];
            }
            return ['ok' => false, 'reason' => 'persist_failed'];
        }

        // PeepSo's add_notification returns the wpdb->insert result of
        // 1 (rows-affected) on success — NOT the insert id. Read the
        // id via wpdb->insert_id which is only valid immediately after
        // insert and within the same request.
        global $wpdb;
        $insertId = isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;
        if ($insertId <= 0) {
            return ['ok' => false, 'reason' => 'persist_failed'];
        }

        return ['ok' => true, 'notification_id' => $insertId];
    }

    /**
     * Mirror PeepSo's internal opt-out / block checks so we can label
     * a FALSE return cleanly. Returns 'opt_out' / 'blocked' when one
     * of those matched, or null when neither did (= real persistence
     * failure).
     */
    private static function detectSkipReason(int $fromUserId, int $toUserId, string $type): ?string
    {
        $optOuts = get_user_meta($toUserId, 'peepso_notifications');
        if (
            isset($optOuts[0])
            && is_array($optOuts[0])
            && in_array($type . '_notification', $optOuts[0], true)
        ) {
            return 'opt_out';
        }

        if (class_exists('PeepSoBlockUsers')) {
            $blockUsers = new \PeepSoBlockUsers();
            // Same arguments PeepSo uses internally:
            // is_user_blocking($from, $to, $strict).
            if ($blockUsers->is_user_blocking($fromUserId, $toUserId, true)) {
                return 'blocked';
            }
        }

        return null;
    }
}
