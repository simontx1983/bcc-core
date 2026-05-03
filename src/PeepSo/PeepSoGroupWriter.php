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
 * V1 scope: open Locals only — `join()` calls `member_join()` directly
 * and assumes the group accepts public membership. Closed-group join
 * requests (`pending_admin` status) are deferred; the V1 plan §E3
 * naming convention treats Locals as open by design. Calling join()
 * against a non-existent or closed group is the caller's problem;
 * this wrapper does no group-policy enforcement.
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
        if (!class_exists('PeepSoGroupUser')) {
            return false;
        }

        $member = new \PeepSoGroupUser($groupId, $userId);
        $member->member_join();

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
            return false;
        }

        $member = new \PeepSoGroupUser($groupId, $userId);
        $member->member_leave();

        // Mirror PeepSo's AJAX leave path so notifications / activity
        // subscribers see the same event whether the user left from
        // PeepSo's UI or from BCC's REST endpoint.
        do_action('peepso_action_group_user_delete', $groupId, $userId);

        return true;
    }
}
