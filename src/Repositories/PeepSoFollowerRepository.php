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
     * Second-degree follow candidates for $viewerId, with the mutual-
     * follow count that connects each to the viewer.
     *
     * "People followed by people you follow" — the canonical friend-of-
     * a-friend pool for a who-to-follow recommender. For each candidate
     * `C` (the passive side of a follow edge whose active side is someone
     * the viewer follows), the returned `mutualCount` is how many of the
     * viewer's followees also follow `C`. That count IS the strength of
     * the second-degree connection — the recommender weights on it.
     *
     * Self and the viewer's direct followees are NOT excluded here — the
     * candidate `C` can legitimately be someone the viewer already
     * follows (they'd just be dropped by the caller's already-following
     * exclusion set). The repository stays a single-purpose graph-walk
     * seam; WHY to drop a candidate is the caller's decision (same
     * convention as PeepSoActivityRepository's `$excludedAuthorIds`).
     *
     * Bounded (§4): the inner edge scan is capped by `$followCap` (the
     * viewer's followees considered — index seek on uf_active_user_id),
     * and the grouped candidate set by `$limit`. Both are hard ceilings;
     * a viewer following tens of thousands of accounts is pathological
     * and the cap keeps the join predictable. ORDER BY is recency-stable
     * (MAX(a2.uf_id) DESC) so the truncation at `$limit` keeps the
     * freshest edges — NOT ranked by mutualCount, popularity, or trust
     * (scoring/ranking is the service layer's job, never the repo's).
     *
     * @return array<int, int> candidate user_id => mutual-follow count
     */
    public static function getSecondDegreeCandidates(
        int $viewerId,
        int $limit = 200,
        int $followCap = 200
    ): array {
        if ($viewerId <= 0 || $limit <= 0) {
            return [];
        }
        if ($limit > 500) {
            $limit = 500;
        }
        if ($followCap <= 0 || $followCap > 500) {
            $followCap = 200;
        }

        global $wpdb;
        $table = self::table();

        // a1: the viewer's follow edges (bounded to $followCap of them).
        // a2: follow edges whose active side is one of those followees.
        // The passive side of a2 is the candidate; COUNT(DISTINCT active)
        // is the mutual-follow count. The viewer themselves is excluded
        // as a candidate (a2.uf_passive_user_id != viewerId).
        $sql = $wpdb->prepare(
            "SELECT a2.uf_passive_user_id AS candidate_id,
                    COUNT(DISTINCT a2.uf_active_user_id) AS mutual_count
               FROM (
                       SELECT uf_passive_user_id
                         FROM {$table}
                        WHERE uf_active_user_id = %d
                          AND uf_follow = 1
                        ORDER BY uf_id DESC
                        LIMIT %d
                    ) AS a1
               INNER JOIN {$table} AS a2
                       ON a2.uf_active_user_id = a1.uf_passive_user_id
                      AND a2.uf_follow = 1
                      AND a2.uf_passive_user_id <> %d
              GROUP BY a2.uf_passive_user_id
              ORDER BY MAX(a2.uf_id) DESC
              LIMIT %d",
            $viewerId,
            $followCap,
            $viewerId,
            $limit
        );

        /** @var list<array{candidate_id: string, mutual_count: string}>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $out = [];
        foreach (($rows ?: []) as $row) {
            $candidateId = (int) $row['candidate_id'];
            if ($candidateId > 0) {
                $out[$candidateId] = (int) $row['mutual_count'];
            }
        }
        return $out;
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

    /**
     * Batched followers-count lookup. Used by list-shape consumers
     * (e.g. /members directory, where 24 rows × `getCounts` would issue
     * 48 sequential COUNT queries). Returns a map keyed on user_id;
     * users with zero followers are absent from the map (callers should
     * default to 0).
     *
     * Followers (passive side) only — directory cards don't surface
     * "following" counts. Add a sibling `getFollowingCountForUsers`
     * if/when a list surface needs it.
     *
     * Empty `$userIds` short-circuits to an empty map (no SQL).
     *
     * @param list<int> $userIds Bounded by caller; the IN-clause is
     *                           expected to be paginated upstream
     *                           (e.g. directory `per_page` cap of 50).
     * @return array<int, int> user_id → followers count
     */
    public static function getFollowersCountForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // Sanitize + dedupe — same pattern as ClaimRepository's batch
        // entry points. Reject zero/negative ids before they hit SQL.
        $clean = [];
        foreach ($userIds as $id) {
            $intVal = (int) $id;
            if ($intVal > 0) {
                $clean[$intVal] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);

        global $wpdb;
        $table       = self::table();
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        // One GROUP BY scan replaces N COUNT(*) queries. The
        // (uf_passive_user_id, uf_follow) composite or the existing
        // index on uf_passive_user_id is what makes this cheap.
        /** @var list<array{user_id: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT uf_passive_user_id AS user_id, COUNT(*) AS c
                   FROM {$table}
                  WHERE uf_passive_user_id IN ({$placeholders})
                    AND uf_follow = 1
                  GROUP BY uf_passive_user_id",
                ...$idList
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(int) $row['user_id']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Batched following-count lookup — the active side (uf_active_user_id),
     * mirroring getFollowersCountForUsers (passive side). Returns the
     * count of accounts each user *follows*. Used by the batched
     * feature-access level resolver (pulls = follows per §C2), where
     * N × getCounts() would issue 2N sequential COUNT queries on the
     * hot feed author-hydration path.
     *
     * Users with zero follows are absent from the map (callers default
     * to 0). Empty `$userIds` short-circuits to an empty map (no SQL).
     *
     * @param list<int> $userIds Bounded by caller (e.g. feed/comment
     *                           author page cap of 50).
     * @return array<int, int> user_id → following count
     */
    public static function getFollowingCountForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $clean = [];
        foreach ($userIds as $id) {
            $intVal = (int) $id;
            if ($intVal > 0) {
                $clean[$intVal] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);

        global $wpdb;
        $table        = self::table();
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        // One GROUP BY scan replaces N COUNT(*) queries. Mirrors the
        // passive-side batch; the index on uf_active_user_id keeps it cheap.
        /** @var list<array{user_id: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT uf_active_user_id AS user_id, COUNT(*) AS c
                   FROM {$table}
                  WHERE uf_active_user_id IN ({$placeholders})
                    AND uf_follow = 1
                  GROUP BY uf_active_user_id",
                ...$idList
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(int) $row['user_id']] = (int) $row['c'];
        }
        return $out;
    }
}
