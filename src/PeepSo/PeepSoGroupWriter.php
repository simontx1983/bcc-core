<?php
/**
 * PeepSoGroupWriter — thin wrapper around PeepSo's official group
 * membership write API per the §C2 / §E3 single-graph rule.
 *
 * BCC must NOT INSERT directly into peepso_group_members — PeepSo
 * owns the write path (status enum, post-meta member-count cache via
 * PeepSoGroupUsers::update_members_count(), integration filters).
 * This wrapper delegates to PeepSoGroupUser's documented methods and
 * fires the same `do_action` hooks PeepSo's AJAX layer fires, so any
 * downstream subscriber (activity stream writer, notification
 * dispatcher) works identically whether the membership change came
 * from the PeepSo UI or from BCC's REST endpoint.
 *
 * Privacy semantics: `PeepSoGroupUser::member_join` writes
 * `gm_user_status = 'member'` unconditionally — it does NOT branch on
 * `is_closed` / `is_secret`. The `pending_admin` state is produced
 * only by PeepSo's frontend AJAX layer (`PeepSoGroupUserAjax::join_request`,
 * which calls `member_modify('pending_admin')` after `member_join`).
 * This wrapper is the trusted-backend door — it bypasses PeepSo's UI
 * gating, which is exactly what BCC's own server-side gates (Locals
 * geofence, NFT-holder gate) need.
 *
 * Counter consistency: PeepSo's group-header template renders the
 * `peepso_group_members_count` post meta, which is recomputed by
 * `PeepSoGroupUsers::update_members_count()`. Member_join itself does
 * not touch that meta — only the AJAX caller does. We mirror the AJAX
 * order (member_join → update_members_count → do_action) so PeepSo's
 * frontend stays in sync with backend writes.
 *
 * @package BCC\Core\PeepSo
 * @since V1 (2026-04, Locals join/leave)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoGroupWriter
{
    /**
     * Join $userId to $groupId.
     *
     * Behavior:
     *   - No existing row → INSERT with gm_user_status='member'
     *   - Existing 'member%' row → idempotent success (no-op)
     *   - Existing pending/banned row → upgraded by PeepSo's member_join
     *     to 'member'; we surface as success
     *
     * Returns:
     *   - true  on success (membership now active)
     *   - false when PeepSoGroupUser is missing (PeepSo deactivated) or
     *           inputs are invalid (zero/negative IDs)
     */
    public static function join(int $userId, int $groupId): bool
    {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }
        if (!class_exists('PeepSoGroupUser') || !class_exists('PeepSoGroupUsers')) {
            // Observability counter: PeepSo absence on a writer hot path
            // (holder-group join). Recorded on every call — the per-method
            // static below dedups the log line, but the metric counter is
            // intentionally per-call so operators see "the join writer
            // silently no-opped 1247 times in the last hour" rather than
            // "we logged it once."
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'group_writer_join');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return false;
        }

        $member = new \PeepSoGroupUser($groupId, $userId);
        $member->member_join();

        // Recompute PeepSo's `peepso_group_members_count` post meta. Without
        // this, the group-header template (group-header.php) renders a stale
        // count whenever a join goes through this wrapper rather than PeepSo's
        // AJAX endpoint. Mirrors groupuserajax.php's update_members_count()
        // call placed between member_join() and the do_action() below.
        (new \PeepSoGroupUsers($groupId))->update_members_count();

        // Mirror PeepSo's AJAX layer (peepso-groups/classes/api/groupuserajax.php)
        // — downstream subscribers expect this hook to fire on join.
        do_action('peepso_action_group_user_join', $groupId, $userId);

        return true;
    }

    /**
     * Remove $userId from $groupId.
     *
     * Behavior:
     *   - Active 'member%' row → row removed by PeepSo's member_leave
     *   - No existing row or pending/banned → idempotent success
     *
     * Returns:
     *   - true  on success (membership now absent)
     *   - false when PeepSoGroupUser is missing or inputs are invalid
     */
    public static function leave(int $userId, int $groupId): bool
    {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }
        if (!class_exists('PeepSoGroupUser')) {
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return false;
        }

        // Refuse to remove the owner — PeepSo's member_leave is
        // unconditional on gm_user_status (does a raw DELETE on
        // (user_id, group_id)) so without this guard we could leave a
        // group ownerless, which PeepSo treats as broken state. Admins
        // who legitimately want to delete a group should do it through
        // PeepSo's group-delete flow, not by removing the owner row.
        $status = \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus($userId, $groupId);
        if ($status === 'member_owner') {
            return false;
        }

        $member = new \PeepSoGroupUser($groupId, $userId);
        $member->member_leave();
        // PeepSo's member_leave() already calls
        // PeepSoGroupUsers::update_members_count() internally, so we
        // do NOT duplicate the call here (unlike join(), where
        // member_join() does not refresh the counter).

        // Mirror PeepSo's AJAX leave path so notifications / activity
        // subscribers see the same event whether the user left from
        // PeepSo's UI or from BCC's REST endpoint.
        do_action('peepso_action_group_user_delete', $groupId, $userId);

        return true;
    }
}
