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
     *   - Existing 'banned' row → REFUSED (returns false); a group-level
     *     ban must stick until an admin lifts it
     *   - Existing pending/block_invites row → upgraded by PeepSo's
     *     member_join to 'member'; we surface as success
     *
     * Returns:
     *   - true  on success (membership now active)
     *   - false when PeepSoGroupUser is missing (PeepSo deactivated),
     *           inputs are invalid (zero/negative IDs), or the user has
     *           a banned membership row in this group
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

        // A group-level ban must stick: PeepSo's member_join falls through
        // to member_modify('member') on ANY existing row — including
        // gm_user_status='banned' — so without this guard every caller
        // (REST joins, auto-join reconcile, future doors) would silently
        // flip an admin's ban back to full membership. Refuse centrally
        // here rather than per-caller so the invariant can't be missed.
        $status = \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus($userId, $groupId);
        if ($status === 'banned') {
            \BCC\Core\Log\Logger::warning('[bcc-core] group join refused — banned membership row', [
                'user_id'  => $userId,
                'group_id' => $groupId,
            ]);
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
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'group_writer_leave');
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

    /**
     * Create a new plain peepso-group owned by $ownerId.
     *
     * V1: name + description + privacy only (open | closed | secret).
     * NFT-gated holder groups and Locals have their own write paths;
     * this method intentionally produces a "plain" user-created group
     * — no gate config, no chain binding, no Local naming prefix.
     *
     * PeepSoGroup's constructor is the documented create entry point.
     * When called with `(null, $dataArray)` it:
     *   1. INSERTs a wp_post (post_type=peepso-group, status=publish)
     *   2. Sets per-property post_meta (privacy, joinable, etc.)
     *   3. Adds the owner as a member via PeepSoGroupUser::member_join
     *      with role=member_owner (triggered by the
     *      `peepso_action_group_create` subscriber chain inside the
     *      peepso-groups plugin)
     *   4. Fires `peepso_action_group_create` for downstream listeners
     *      (activity stream, notifications, BCC's GatedGroupProvisioning,
     *      etc.)
     *
     * Privacy values mirror PeepSoGroupPrivacy constants:
     *   0 = open    (anyone can join)
     *   1 = closed  (request to join, admin approves)
     *   2 = secret  (invite-only; doesn't surface in discovery)
     *
     * Returns the new group_id on success, 0 on failure (PeepSo
     * unavailable, invalid input, wp_insert_post error).
     */
    public static function createPlainGroup(
        int $ownerId,
        string $name,
        string $description,
        int $privacy,
        int $chainTagId = 0,
        int $trustGateMin = 0
    ): int {
        if ($ownerId <= 0 || $name === '') {
            return 0;
        }
        if (!in_array($privacy, [0, 1, 2], true)) {
            return 0;
        }
        // Trust gate values must be one of the canonical tiers — server
        // rejects everything else so a creative client can't smuggle in
        // arbitrary thresholds (e.g. 1, 999) that would either trivialize
        // the gate or lock out every viewer. 0 means "no trust gate."
        if ($trustGateMin !== 0 && !in_array($trustGateMin, [25, 50, 75], true)) {
            return 0;
        }
        if (!class_exists('PeepSoGroup')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'group_writer_create');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return 0;
        }

        $data = [
            'owner_id'    => $ownerId,
            'name'        => $name,
            'description' => $description,
            'meta'        => [
                // Mirrors PeepSoGroupPrivacy::PRIVACY_OPEN/CLOSED/SECRET.
                'privacy' => $privacy,
            ],
        ];

        $group = new \PeepSoGroup(null, $data);

        // PeepSoGroup returns FALSE from its constructor's get_posts
        // fallback when the wp_post insert failed. The id property
        // is the canonical post-creation success signal.
        $groupId = (int) $group->get('id');
        if ($groupId <= 0) {
            return 0;
        }

        // Chain-tag binding — IMMUTABLE per the create-flow contract.
        // Written outside PeepSoGroup's meta_data_map because that map
        // is PeepSo's own (peepso_group_* keys + their schema). The
        // BCC chain tag lives in its own `_bcc_chain_tag` key so it
        // doesn't collide with NFT gating (`_bcc_gate_chain_id`) or
        // PeepSo's own meta surface. The user-facing form locks this
        // field at creation; we deliberately use add_post_meta (not
        // update_post_meta) so a future code path that tries to mutate
        // it surfaces as a no-op (existing key + unique=true is a
        // PeepSo-style guard rail).
        if ($chainTagId > 0) {
            add_post_meta($groupId, '_bcc_chain_tag', (string) $chainTagId, true);
        }

        // Trust gate — IMMUTABLE per the create-flow contract. Same
        // unique=true add_post_meta posture as the chain tag so a
        // bug that tries to rewrite the threshold is a silent no-op
        // rather than a stealth tier downgrade. MyGroupsEndpoint::postJoin
        // reads this meta and rejects joins from viewers whose
        // reputation score falls below the threshold.
        if ($trustGateMin > 0) {
            add_post_meta($groupId, '_bcc_trust_gate_min', (string) $trustGateMin, true);
        }

        return $groupId;
    }
}
