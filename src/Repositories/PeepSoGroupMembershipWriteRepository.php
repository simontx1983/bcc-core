<?php
/**
 * Conditional writes to PeepSo's group membership rows — F-272 only.
 *
 * ⚠ A deliberate, narrow exception to §C2 ("PeepSo owns the write path").
 * PeepSo's own API cannot express the F-272 transitions safely:
 *   - member_join() always INSERTs a FULL member, so "insert, then
 *     member_modify('pending_*')" leaves a full member behind whenever the
 *     second call fails — an approval bypass;
 *   - member_modify() / member_leave() write by (user, group) whatever the
 *     row's current status, so a read-then-write can overwrite a ban that
 *     landed in between (the F-266 class of bug).
 * Every statement here therefore names the status it expects in its WHERE
 * clause: the row changes only if it is still in that state, and the
 * affected-row count tells the caller whether it did. Callers (only
 * PeepSoGroupWriter) refresh PeepSo's member-count meta and fire PeepSo's
 * hooks afterwards, exactly as the AJAX layer would.
 *
 * ⚠ peepso_group_members has NO unique index on (gm_user_id, gm_group_id)
 * (PeepSo's install/activate.php: single-column indexes only), so the
 * NOT EXISTS insert below is atomic only against writers that serialize on
 * the same pair — bcc-trust's GroupJoinRequestService holds a per-(user,
 * group) advisory lock around every F-272 door. PeepSo's own UI doors do
 * not take it; BCC's headless frontend does not use them.
 *
 * Every method returns the affected-row count (0 or 1) or false when the
 * statement failed — a failure is never reported as "nothing to change".
 *
 * @package BCC\Core\Repositories
 */

declare(strict_types=1);

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoGroupMembershipWriteRepository
{
    private const TABLE_MEMBERS_SUFFIX = 'peepso_group_members';

    /** The only statuses this repository may write or match. */
    private const PENDING  = ['pending_admin', 'pending_user'];
    private const MEMBER   = 'member';

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_MEMBERS_SUFFIX;
    }

    /**
     * Create a pending row only when the pair has NO row at all (any status,
     * banned included). 1 = created, 0 = a row already exists.
     */
    public static function insertPendingIfAbsent(int $userId, int $groupId, string $status, ?int $invitedBy): int|false
    {
        if ($userId <= 0 || $groupId <= 0 || !in_array($status, self::PENDING, true)) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        // gm_invited_by_id stays NULL for a request (no inviter).
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (gm_user_id, gm_group_id, gm_user_status, gm_invited_by_id)
             SELECT %d, %d, %s, NULLIF(%d, 0)
               FROM DUAL
              WHERE NOT EXISTS (
                    SELECT 1 FROM {$table}
                     WHERE gm_user_id = %d AND gm_group_id = %d
              )",
            $userId,
            $groupId,
            $status,
            $invitedBy ?? 0,
            $userId,
            $groupId
        ));

        return self::affected($result);
    }

    /**
     * Pending → member, only while the row is still in $fromStatus.
     * gm_joined is reset to now: the member joined at approval/acceptance,
     * not when they asked (the column held the request time until here).
     */
    public static function promotePending(int $userId, int $groupId, string $fromStatus, ?int $acceptedBy): int|false
    {
        if ($userId <= 0 || $groupId <= 0 || !in_array($fromStatus, self::PENDING, true)) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET gm_user_status    = %s,
                    gm_joined         = CURRENT_TIMESTAMP,
                    gm_accepted_by_id = NULLIF(%d, 0)
              WHERE gm_user_id = %d AND gm_group_id = %d AND gm_user_status = %s
              LIMIT 1",
            self::MEMBER,
            $acceptedBy ?? 0,
            $userId,
            $groupId,
            $fromStatus
        ));

        return self::affected($result);
    }

    /** Delete the pair's row only while it is still in $status (pending only). */
    public static function deletePending(int $userId, int $groupId, string $status): int|false
    {
        if ($userId <= 0 || $groupId <= 0 || !in_array($status, self::PENDING, true)) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
              WHERE gm_user_id = %d AND gm_group_id = %d AND gm_user_status = %s
              LIMIT 1",
            $userId,
            $groupId,
            $status
        ));

        return self::affected($result);
    }

    /** query() result → 0/1, or false on a failed statement. */
    private static function affected(mixed $result): int|false
    {
        global $wpdb;
        if ($result === false || (string) $wpdb->last_error !== '') {
            return false;
        }
        return (int) $result > 0 ? 1 : 0;
    }
}
