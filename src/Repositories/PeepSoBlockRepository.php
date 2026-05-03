<?php
/**
 * PeepSoBlockRepository — read-only access to peepso_blocks for the
 * §K1 block surface.
 *
 * Bypasses PeepSo's `is_user_blocking` (which gates on the
 * `user_blocking_enable` admin option) so blocks are always queryable
 * regardless of admin settings — matching the PeepSoBlockWriter design.
 *
 * No writes — all writes route through PeepSoBlockWriter.
 *
 * @package BCC\Core\Repositories
 * @since V1 (2026-04, §K1 Phase A blocks)
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoBlockRepository
{
    private const TABLE_SUFFIX = 'peepso_blocks';

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Is $blockerId currently blocking $blockedId?
     *
     * One-way check — symmetric blocks are two rows. Use
     * `isMutuallyBlocked` if you need both directions.
     */
    public static function isBlocking(int $blockerId, int $blockedId): bool
    {
        if ($blockerId <= 0 || $blockedId <= 0 || $blockerId === $blockedId) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE blk_user_id = %d
                AND blk_blocked_id = %d
              LIMIT 1",
            $blockerId,
            $blockedId
        ));
        return $count > 0;
    }

    /**
     * Either-direction check — used for view-model gating ("don't show
     * Alice the post if she blocked Bob OR Bob blocked her").
     */
    public static function isMutuallyBlocked(int $userA, int $userB): bool
    {
        if ($userA <= 0 || $userB <= 0 || $userA === $userB) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE (blk_user_id = %d AND blk_blocked_id = %d)
                 OR (blk_user_id = %d AND blk_blocked_id = %d)
              LIMIT 1",
            $userA, $userB,
            $userB, $userA
        ));
        return $count > 0;
    }

    /**
     * IDs of users $blockerId is blocking. Bounded by LIMIT to keep
     * the feed-exclusion merge predictable; users with > 500 blocks
     * are pathological and almost certainly an abuse signal.
     *
     * @return list<int>
     */
    public static function getBlockedIds(int $blockerId, int $limit = 500): array
    {
        if ($blockerId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT blk_blocked_id FROM {$table}
              WHERE blk_user_id = %d
              LIMIT %d",
            $blockerId,
            $limit
        ));

        $ids = [];
        foreach ($rows as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * IDs of users who are blocking $blockedId. Used to suppress the
     * blocked-by-them user from seeing the blocker (mutual invisibility).
     *
     * @return list<int>
     */
    public static function getBlockerIds(int $blockedId, int $limit = 500): array
    {
        if ($blockedId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT blk_user_id FROM {$table}
              WHERE blk_blocked_id = %d
              LIMIT %d",
            $blockedId,
            $limit
        ));

        $ids = [];
        foreach ($rows as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Count of blocks by $blockerId. Cheap aggregate.
     */
    public static function countByBlocker(int $blockerId): int
    {
        if ($blockerId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE blk_user_id = %d",
            $blockerId
        ));
    }

    /**
     * Hydrated list of blocks for the /me/blocks settings page —
     * joins wp_users for display name + user_login.
     *
     * @return list<object{
     *   blk_blocked_id: int|numeric-string,
     *   user_login: string|null,
     *   display_name: string|null,
     *   blk_id: int|numeric-string
     * }>
     */
    public static function listForBlocker(int $blockerId, int $limit, int $offset): array
    {
        if ($blockerId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT b.blk_id, b.blk_blocked_id, u.user_login, u.display_name
               FROM {$table} b
               LEFT JOIN {$wpdb->users} u ON u.ID = b.blk_blocked_id
              WHERE b.blk_user_id = %d
              ORDER BY b.blk_id DESC
              LIMIT %d OFFSET %d",
            $blockerId,
            $limit,
            $offset
        ));

        /** @var list<object{
         *   blk_blocked_id: int|numeric-string,
         *   user_login: string|null,
         *   display_name: string|null,
         *   blk_id: int|numeric-string
         * }> $rows
         */
        return $rows ?: [];
    }
}
