<?php

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to the PeepSo follower graph.
 *
 * Backing table: {prefix}peepso_user_followers
 * Columns:
 *   - uf_id              PK
 *   - uf_active_user_id  the follower (the user doing the following)
 *   - uf_passive_user_id the followed (the user being followed)
 *   - uf_follow          1 = follow, 0 = block
 *
 * Canonical follow predicate: uf_follow = 1.
 *
 * No SELECT *. All queries bounded by user_id index + LIMIT.
 * No writes — PeepSo owns the write path.
 */
final class PeepSoFollowerRepository
{
    private const TABLE_SUFFIX = 'peepso_user_followers';

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * IDs of users that $userId follows.
     *
     * @return list<int>
     */
    public static function getFollowing(int $userId, int $limit = 200, int $offset = 0): array
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            'SELECT uf_passive_user_id
               FROM ' . self::table() . '
              WHERE uf_active_user_id = %d
                AND uf_follow = 1
              ORDER BY uf_id DESC
              LIMIT %d OFFSET %d',
            $userId,
            $limit,
            $offset
        );

        $rows = $wpdb->get_col($sql);
        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * IDs of users that follow $userId.
     *
     * @return list<int>
     */
    public static function getFollowers(int $userId, int $limit = 200, int $offset = 0): array
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            'SELECT uf_active_user_id
               FROM ' . self::table() . '
              WHERE uf_passive_user_id = %d
                AND uf_follow = 1
              ORDER BY uf_id DESC
              LIMIT %d OFFSET %d',
            $userId,
            $limit,
            $offset
        );

        $rows = $wpdb->get_col($sql);
        return array_values(array_map('intval', $rows ?: []));
    }

    public static function isFollowing(int $viewerId, int $targetId): bool
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT 1
               FROM ' . self::table() . '
              WHERE uf_active_user_id = %d
                AND uf_passive_user_id = %d
                AND uf_follow = 1
              LIMIT 1',
            $viewerId,
            $targetId
        ));
        return (bool) $exists;
    }

    /**
     * Users that $viewerId follows AND that also follow $targetId.
     * I.e. "people you follow who also follow X."
     *
     * @return list<int>
     */
    public static function getMutualFollowsOfTarget(int $viewerId, int $targetId, int $limit = 50): array
    {
        global $wpdb;
        $table = self::table();

        $sql = $wpdb->prepare(
            "SELECT a.uf_passive_user_id
               FROM {$table} AS a
               INNER JOIN {$table} AS b
                 ON b.uf_active_user_id = a.uf_passive_user_id
                AND b.uf_passive_user_id = %d
                AND b.uf_follow = 1
              WHERE a.uf_active_user_id = %d
                AND a.uf_follow = 1
              ORDER BY a.uf_id DESC
              LIMIT %d",
            $targetId,
            $viewerId,
            $limit
        );

        $rows = $wpdb->get_col($sql);
        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * Counts only — used for view-model headlines and badges.
     *
     * @return array{following: int, followers: int}
     */
    public static function getCounts(int $userId): array
    {
        global $wpdb;
        $table = self::table();

        $following = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE uf_active_user_id = %d AND uf_follow = 1",
            $userId
        ));
        $followers = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE uf_passive_user_id = %d AND uf_follow = 1",
            $userId
        ));

        return ['following' => $following, 'followers' => $followers];
    }
}
