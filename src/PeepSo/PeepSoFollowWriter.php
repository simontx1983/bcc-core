<?php
/**
 * PeepSoFollowWriter — thin wrapper around PeepSo's official follow
 * write API (PeepSoUserFollower) per the §C2 single-graph rule.
 *
 * BCC must NOT INSERT directly into peepso_user_followers — PeepSo
 * owns the write path (cache invalidation, bookkeeping in
 * `count_followers` / `count_following`, integration filters). This
 * wrapper delegates to PeepSoUserFollower's documented constructor
 * pattern and exposes:
 *
 *   - follow(active, passive): int  → returns the uf_id (the follow_id
 *                                     that bcc_pull_meta will reference)
 *   - unfollow(active, passive): bool
 *
 * The PeepSoUserFollower class is loaded by PeepSo itself; we
 * class_exists-check defensively but otherwise depend on it being
 * available (PeepSo is a hard sibling-plugin dependency for BCC).
 *
 * @package BCC\Core\PeepSo
 * @since V1 (2026-04, Binder Phase 2)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoFollowWriter
{
    /**
     * Create or re-activate a follow from $activeUserId → $passiveUserId.
     *
     * Behavior:
     *   - No existing row → INSERT with uf_follow=1; returns new uf_id
     *   - Existing row with uf_follow=1 → idempotent, returns existing uf_id
     *   - Existing row with uf_follow=0 → flips to uf_follow=1, returns existing uf_id
     *
     * Returns 0 on:
     *   - PeepSoUserFollower class missing (PeepSo deactivated)
     *   - self-follow attempt (active === passive)
     *   - invalid user IDs (zero or negative)
     *   - the constructor's contract failed (DB error path)
     */
    public static function follow(int $activeUserId, int $passiveUserId): int
    {
        if ($activeUserId <= 0 || $passiveUserId <= 0 || $activeUserId === $passiveUserId) {
            return 0;
        }
        if (!class_exists('PeepSoUserFollower')) {
            return 0;
        }

        // PeepSo's canonical upsert: constructor with $create=true.
        new \PeepSoUserFollower($passiveUserId, $activeUserId, true);

        // PeepSoUserFollower doesn't expose uf_id — read it back from
        // the table. Bounded by unique (active, passive) pair.
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_user_followers';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT uf_id FROM {$table}
              WHERE uf_active_user_id = %d AND uf_passive_user_id = %d
              LIMIT 1",
            $activeUserId,
            $passiveUserId
        ));
    }

    /**
     * Set uf_follow=0 on the relationship $activeUserId → $passiveUserId.
     *
     * PeepSo's convention is to keep the row alive and flip the flag
     * (NOT DELETE the row). The bcc_pull_meta sidecar references
     * uf_id, so the row must persist; the binder's read query already
     * filters by uf_follow=1 so flipped rows naturally drop out of
     * the binder view. Deleting bcc_pull_meta is the caller's job.
     *
     * Returns:
     *   - true  when the relationship existed and was flipped (or was already 0)
     *   - false when no row exists, or PeepSoUserFollower is missing
     */
    public static function unfollow(int $activeUserId, int $passiveUserId): bool
    {
        if ($activeUserId <= 0 || $passiveUserId <= 0) {
            return false;
        }
        if (!class_exists('PeepSoUserFollower')) {
            return false;
        }

        $follower = new \PeepSoUserFollower($passiveUserId, $activeUserId, false);
        if (!$follower->is_follower) {
            return false;
        }

        if ((int) $follower->follow === 0) {
            // Already unfollowed — idempotent.
            return true;
        }

        $follower->set('follow', 0);
        return true;
    }
}
