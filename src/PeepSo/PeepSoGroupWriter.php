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

    /**
     * Transfer ownership of $groupId from $fromUserId to $toUserId.
     *
     * This is the SANCTIONED WRITE PATH ONLY (Rank Phase 7, §21.2). The
     * BCC policy gates — capability resolution (transfer_community /
     * receive_community), ownership caps, and the 30-day custody
     * cooldown — live in bcc-trust (CapabilityResolver +
     * CommunityCustodyService); callers MUST gate before invoking per
     * the peepso-write-guard rule. The preconditions below are defense
     * in depth, not the policy.
     *
     * Mirrors PeepSo's canonical owner-change sequence exactly
     * (peepso-groups/classes/api/groupuserajax.php, member_modify with
     * role=member_owner):
     *
     *   1. Capture the current owner from the PeepSoGroup model.
     *   2. Promote the receiver's EXISTING membership row to
     *      member_owner via PeepSoGroupUser::member_modify (which also
     *      refreshes the members-count post meta internally).
     *   3. Switch the owner pointer: $group->update(['owner_id' => …])
     *      (post_author via PeepSo's post_data_map), then VERIFY the
     *      pointer moved by re-reading the group — PeepSoGroup::update()
     *      is void and swallows wp_update_post failure (step 3b below).
     *   4. Demote the previous owner to member_manager.
     *   5. Fire `peepso_action_group_user_role_change_manager` (old
     *      owner) then `peepso_action_group_user_role_change_owner`
     *      (new owner) — same order as PeepSo's AJAX layer, so
     *      notification/activity subscribers behave identically.
     *
     * Preconditions (all failures return false + Logger::error):
     *   - group exists (PeepSoGroup resolves the id)
     *   - $fromUserId IS the current owner (both the post_author-backed
     *     owner_id AND the gm_user_status='member_owner' row)
     *   - $toUserId has an ACTIVE membership row (gm_user_status LIKE
     *     'member%', not owner). NEVER inserts one — §C2 single-graph:
     *     membership creation goes through join(), not through a
     *     transfer side effect.
     *
     * Returns true only when the full sequence completed. A mid-sequence
     * failure (e.g. the demote UPDATE failing after the promote landed)
     * returns false with an error log describing the partial state —
     * PeepSo tolerates the intermediate shape (its own AJAX layer does
     * not check these writes at all) and a retry is safe.
     */
    public static function transferOwnership(int $groupId, int $fromUserId, int $toUserId): bool
    {
        if ($groupId <= 0 || $fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            return false;
        }
        if (!class_exists('PeepSoGroup') || !class_exists('PeepSoGroupUser')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'group_writer_transfer');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return false;
        }

        // Group must exist. PeepSoGroup's constructor resolves via
        // get_posts; a missing/foreign id leaves the id unresolved.
        $group = new \PeepSoGroup($groupId);
        if ((int) $group->get('id') !== $groupId) {
            \BCC\Core\Log\Logger::error('[bcc-core] group transfer refused — group not found', [
                'group_id' => $groupId,
            ]);
            return false;
        }

        // $fromUserId must be the CURRENT owner on both books: the
        // post_author-backed owner_id AND the membership-row role. A
        // mismatch means the caller is stale (or the two stores drifted)
        // — refuse rather than guess.
        $currentOwnerId = (int) $group->get('owner_id');
        if ($currentOwnerId !== $fromUserId) {
            \BCC\Core\Log\Logger::error('[bcc-core] group transfer refused — from-user is not the owner', [
                'group_id'      => $groupId,
                'from_user_id'  => $fromUserId,
                'current_owner' => $currentOwnerId,
            ]);
            return false;
        }
        $fromStatus = \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus($fromUserId, $groupId);
        if ($fromStatus !== 'member_owner') {
            \BCC\Core\Log\Logger::error('[bcc-core] group transfer refused — owner membership row missing', [
                'group_id'     => $groupId,
                'from_user_id' => $fromUserId,
                'from_status'  => (string) $fromStatus,
            ]);
            return false;
        }

        // Receiver must ALREADY be an active member (gm_user_status LIKE
        // 'member%' — same ACTIVE convention as PeepSoGroupRepository).
        // pending_* / banned / block_invites / absent rows are refused;
        // we never insert a membership row here (§C2 single-graph).
        $toStatus = \BCC\Core\Repositories\PeepSoGroupRepository::getMembershipStatus($toUserId, $groupId);
        if ($toStatus === null || strpos($toStatus, 'member') !== 0 || $toStatus === 'member_owner') {
            \BCC\Core\Log\Logger::error('[bcc-core] group transfer refused — receiver has no active membership', [
                'group_id'   => $groupId,
                'to_user_id' => $toUserId,
                'to_status'  => (string) $toStatus,
            ]);
            return false;
        }

        // Step 2 — promote the receiver's existing row to member_owner.
        // member_modify returns FALSE on a wpdb UPDATE failure and also
        // refreshes PeepSo's members-count post meta internally.
        $receiver = new \PeepSoGroupUser($groupId, $toUserId);
        if ($receiver->member_modify('member_owner') !== true) {
            \BCC\Core\Log\Logger::error('[bcc-core] group transfer failed — receiver promote did not apply', [
                'group_id'   => $groupId,
                'to_user_id' => $toUserId,
            ]);
            return false;
        }

        // Step 3 — switch the owner pointer (post_author). PeepSo's
        // update() maps owner_id through post_data_map → wp_update_post.
        $group->update(['owner_id' => $toUserId]);

        // Step 3b — VERIFY the pointer actually moved. PeepSoGroup::update()
        // is void and swallows a wp_update_post failure, so a silent miss
        // here would leave the receiver promoted (step 2) while post_author
        // still names the old owner — and we'd then demote the old owner
        // and fire success hooks on top of a broken pointer. Re-read via a
        // fresh PeepSoGroup (wp_update_post cleans the post cache, so this
        // reflects the DB) and fail fast BEFORE the step-4 demote and the
        // step-5 hooks, which have not run yet. PARTIAL-state semantics on
        // this branch: receiver row = member_owner, old-owner row =
        // member_owner, owner_id = old owner. No rollback of the
        // already-applied step-2 promote is attempted; note that a retry
        // of transferOwnership will be REFUSED by the receiver-status
        // precondition (to_status is now member_owner), so the operator
        // sees the partial state loudly instead of a double-fire.
        $reread = new \PeepSoGroup($groupId);
        if ((int) $reread->get('owner_id') !== $toUserId) {
            \BCC\Core\Log\Logger::error('[bcc-core] group transfer PARTIAL — owner pointer did not move', [
                'group_id'      => $groupId,
                'from_user_id'  => $fromUserId,
                'to_user_id'    => $toUserId,
                'current_owner' => (int) $reread->get('owner_id'),
            ]);
            return false;
        }

        // Step 4 — demote the previous owner to member_manager. On
        // failure the group would carry TWO member_owner rows — surface
        // loudly and return false; a retry re-runs the sequence safely
        // (the owner_id check above will then refuse, so the operator
        // sees the partial state instead of a silent success).
        $oldOwner = new \PeepSoGroupUser($groupId, $fromUserId);
        if ($oldOwner->member_modify('member_manager') !== true) {
            \BCC\Core\Log\Logger::error('[bcc-core] group transfer PARTIAL — old-owner demote did not apply', [
                'group_id'     => $groupId,
                'from_user_id' => $fromUserId,
                'to_user_id'   => $toUserId,
            ]);
            return false;
        }

        // Step 5 — fire both role-change hooks in PeepSo's AJAX order
        // (manager for the demoted old owner, then owner for the
        // receiver) so downstream subscribers see identical events.
        do_action('peepso_action_group_user_role_change_manager', $groupId, $fromUserId);
        do_action('peepso_action_group_user_role_change_owner', $groupId, $toUserId);

        return true;
    }
}
