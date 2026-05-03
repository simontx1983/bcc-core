<?php
/**
 * PeepSoBlockWriter — wraps PeepSo's `peepso_blocks` table for the
 * §K1 Phase A block surface.
 *
 * Mirrors PeepSoFollowWriter's design (single-graph rule §C2): BCC is
 * the sole writer for any moderation-relevant relationship. We INSERT
 * directly into peepso_blocks so:
 *
 *   1. The check is unconditional. PeepSo's `PeepSoBlockUsers::is_user_blocking`
 *      gates on the `user_blocking_enable` admin option — useful in
 *      PeepSo's UI flow but wrong for BCC where blocks must always be
 *      enforceable regardless of admin settings.
 *
 *   2. We can be idempotent. PeepSo's `block_user_from_user` happily
 *      writes duplicate rows; we SELECT first so a double-tap doesn't
 *      pollute the table.
 *
 *   3. We can fire BCC's own `bcc_user_blocked` event AND PeepSo's
 *      legacy `peepso_user_blocked` hook in the same write — keeping
 *      both subscribers in sync.
 *
 * Schema (peepso_blocks):
 *   - blk_id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
 *   - blk_user_id     blocker (the viewer doing the blocking)
 *   - blk_blocked_id  blocked (the user the viewer wants gone)
 *
 * @package BCC\Core\PeepSo
 * @since V1 (2026-04, §K1 Phase A blocks)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoBlockWriter
{
    private const TABLE_SUFFIX = 'peepso_blocks';

    /**
     * Add a block. Idempotent — calling twice on the same pair is a no-op
     * after the first insert.
     *
     * Self-block is rejected (returns 'invalid') so a misconfigured client
     * can't write `blk_user_id == blk_blocked_id` (which would silently
     * exclude the user from their own feed via the §K1 exclusion merge).
     *
     * Return values:
     *   - 'created'   first time this (blocker, blocked) pair was written
     *   - 'existing'  pair already existed; no row inserted, no events fired
     *   - 'invalid'   self-block or non-positive id; nothing happened
     *   - 'error'     wpdb insert failed
     *
     * Events fire only on 'created'. Subsequent blocks are silent so
     * audit trails don't double-count.
     *
     * @return 'created'|'existing'|'invalid'|'error'
     */
    public static function block(int $blockerId, int $blockedId): string
    {
        if ($blockerId <= 0 || $blockedId <= 0 || $blockerId === $blockedId) {
            return 'invalid';
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // Idempotency check — peepso_blocks has no unique key on the
        // (blocker, blocked) pair, so we read-then-write rather than
        // relying on INSERT IGNORE.
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE blk_user_id = %d
                AND blk_blocked_id = %d",
            $blockerId,
            $blockedId
        ));
        if ($existing > 0) {
            return 'existing';
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'blk_user_id'    => $blockerId,
                'blk_blocked_id' => $blockedId,
            ],
            ['%d', '%d']
        );
        if ($inserted === false) {
            return 'error';
        }

        // Legacy PeepSo hook — keeps any third-party subscriber that
        // listens on PeepSo's wire intact.
        do_action('peepso_user_blocked', ['from' => $blockerId, 'to' => $blockedId]);

        // §A3 BCC event bus — primary subscriber surface.
        do_action('bcc_user_blocked', $blockerId, $blockedId);

        return 'created';
    }

    /**
     * Remove a block. Returns true if a row was deleted; false otherwise
     * (including when no block existed — caller can treat that as a
     * success too, but the truth signal is here for callers who care).
     */
    public static function unblock(int $blockerId, int $blockedId): bool
    {
        if ($blockerId <= 0 || $blockedId <= 0) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $deleted = $wpdb->delete(
            $table,
            [
                'blk_user_id'    => $blockerId,
                'blk_blocked_id' => $blockedId,
            ],
            ['%d', '%d']
        );
        if ($deleted === false || $deleted === 0) {
            return false;
        }

        do_action('peepso_user_unblocked', ['from' => $blockerId, 'to' => $blockedId]);
        do_action('bcc_user_unblocked', $blockerId, $blockedId);

        return true;
    }
}
