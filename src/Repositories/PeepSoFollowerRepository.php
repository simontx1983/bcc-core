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
    /**
     * Cap on `$viewerFriendIds`, mirroring
     * {@see PeepSoActivityRepository::VIEWER_FRIEND_IDS_MAX}.
     *
     * ⚠ Over the cap the list is treated as EMPTY, which DENIES every
     * level-30 row rather than admitting the first N. Truncating would
     * make who appears in a follower list depend on array order.
     */
    public const VIEWER_FRIEND_IDS_MAX = 500;

    /**
     * The §F-02 profile-visibility predicate for a follow row, applied to
     * whichever column holds the LISTED user.
     *
     * ⚠⚠ THE POINT IS THAT THE LIST AND THE COUNT SHARE THIS. A follower
     * list filtered in PHP after a LIMIT/OFFSET query under-fills its page
     * AND leaves `total` counting rows the viewer cannot see. A count that
     * disagrees with its list announces "there are N hidden followers" —
     * a smaller leak, but still one, and the kind of inconsistency that
     * gets filed as a bug anyway. Both the id query and the COUNT call
     * this, so they cannot drift apart.
     *
     * Unlike the per-post gate there is no second level to combine: a
     * follow row has no `act_access`, so the listed user's
     * `usr_profile_acc` IS the effective level.
     *
     * No join to the optional peepso-friends table — the viewer's friend
     * ids are passed in, for the same reason as the per-post gate: that
     * table belongs to an add-on which may not be installed, and a
     * missing table would turn these reads into SQL errors.
     *
     * @param list<int> $friendIds already cleaned and capped by the caller
     * @return array{0: string, 1: string, 2: list<int>} [join, where, params]
     */
    private static function visibilityClause(
        string $listedCol,
        int $viewerId,
        array $friendIds
    ): array {
        global $wpdb;

        $join = ' LEFT JOIN ' . $wpdb->prefix . 'peepso_users pu ON pu.usr_id = ' . $listedCol;

        $friendBranch = '0';
        $params       = [$viewerId, $viewerId, $viewerId];
        if ($viewerId > 0 && $friendIds !== []) {
            $friendBranch = 'IF(' . $listedCol . ' IN ('
                . implode(',', array_fill(0, count($friendIds), '%d'))
                . '), 1, 0)';
            foreach ($friendIds as $fid) {
                $params[] = $fid;
            }
        }

        // The viewer always sees THEMSELVES, and that must be in the SQL
        // because a page carries many users. An unrecognised level DENIES:
        // there is deliberately no admitting catch-all, the same posture
        // as the per-post ladder.
        $where = ' AND (CASE WHEN %d > 0 AND ' . $listedCol . ' = %d THEN 1'
            . ' ELSE CASE IF(pu.usr_profile_acc IS NULL OR pu.usr_profile_acc <= 0, 10, pu.usr_profile_acc)'
            .   ' WHEN 10 THEN 1'
            .   ' WHEN 20 THEN IF(%d > 0, 1, 0)'
            .   ' WHEN 30 THEN ' . $friendBranch
            .   ' ELSE 0'
            . ' END END) = 1';

        return [$join, $where, $params];
    }

    /**
     * Clean and cap a caller-supplied friend list: positive ints only,
     * de-duplicated, and emptied entirely if it exceeds the cap.
     *
     * @param list<int>|null $friendIds
     * @return list<int>
     */
    private static function cleanFriendIds(?array $friendIds): array
    {
        $clean = [];
        foreach ((array) $friendIds as $fid) {
            $f = (int) $fid;
            if ($f > 0) {
                $clean[$f] = true;
            }
        }
        $out = array_keys($clean);

        return count($out) > self::VIEWER_FRIEND_IDS_MAX ? [] : $out;
    }

    /**
     * The users $userId follows, newest first.
     *
     * @param list<int>|null $viewerFriendIds The authors the VIEWER is a friend of, for the
     *                                      level-30 branch. null/[] = a friend of nobody, which
     *                                      DENIES level 30 to all but the listed user themselves.
     *                                      Capped at {@see self::VIEWER_FRIEND_IDS_MAX}; over the
     *                                      cap the list is emptied, never truncated.
     * @return list<int>
     */
    public static function getFollowing(
        int $userId,
        int $limit = 200,
        int $offset = 0,
        int $viewerId = 0,
        ?array $viewerFriendIds = null,
        bool $enforceProfileVisibility = false
    ): array {
        global $wpdb;

        $visJoin   = '';
        $visWhere  = '';
        $visParams = [];
        if ($enforceProfileVisibility) {
            [$visJoin, $visWhere, $visParams] = self::visibilityClause(
                'uf_passive_user_id',
                $viewerId,
                self::cleanFriendIds($viewerFriendIds)
            );
        }

        $sql = $wpdb->prepare(
            'SELECT uf_passive_user_id
               FROM ' . self::table() . $visJoin . '
              WHERE uf_active_user_id = %d
                AND uf_follow = 1' . $visWhere . '
              ORDER BY uf_id DESC
              LIMIT %d OFFSET %d',
            // Order mirrors placeholder appearance: the owner, then the
            // visibility clause's own params, then the page bounds.
            // Built as one array because PHP forbids positional
            // arguments after an unpack.
            ...array_merge([$userId], $visParams, [$limit, $offset])
        );

        $rows = $wpdb->get_col($sql);
        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * IDs of users that follow $userId.
     *
     * @param list<int>|null $viewerFriendIds The authors the VIEWER is a friend of, for the
     *                                      level-30 branch. null/[] = a friend of nobody, which
     *                                      DENIES level 30 to all but the listed user themselves.
     *                                      Capped at {@see self::VIEWER_FRIEND_IDS_MAX}; over the
     *                                      cap the list is emptied, never truncated.
     * @return list<int>
     */
    public static function getFollowers(
        int $userId,
        int $limit = 200,
        int $offset = 0,
        int $viewerId = 0,
        ?array $viewerFriendIds = null,
        bool $enforceProfileVisibility = false
    ): array {
        global $wpdb;

        $visJoin   = '';
        $visWhere  = '';
        $visParams = [];
        if ($enforceProfileVisibility) {
            [$visJoin, $visWhere, $visParams] = self::visibilityClause(
                'uf_active_user_id',
                $viewerId,
                self::cleanFriendIds($viewerFriendIds)
            );
        }

        $sql = $wpdb->prepare(
            'SELECT uf_active_user_id
               FROM ' . self::table() . $visJoin . '
              WHERE uf_passive_user_id = %d
                AND uf_follow = 1' . $visWhere . '
              ORDER BY uf_id DESC
              LIMIT %d OFFSET %d',
            ...array_merge([$userId], $visParams, [$limit, $offset])
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
     * Of $candidateIds, the subset that $viewerId actively follows.
     *
     * A bounded membership probe on the specific candidates — used by the feed
     * follow-state hydrator so it stays correct for viewers who follow more
     * than getFollowing()'s page size. The prior path intersected against the
     * newest-200 follow set and marked anyone older as "not followed". Feed
     * pages carry at most a few dozen distinct authors, so the IN() is small.
     * [audit M-B1]
     *
     * @param list<int> $candidateIds
     * @return list<int>
     */
    public static function filterFollowed(int $viewerId, array $candidateIds): array
    {
        if ($viewerId <= 0 || $candidateIds === []) {
            return [];
        }

        $clean = [];
        foreach ($candidateIds as $id) {
            $i = (int) $id;
            if ($i > 0 && $i !== $viewerId) {
                $clean[$i] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        // Bound the IN() defensively — a feed page has a few dozen authors.
        $ids          = array_slice(array_keys($clean), 0, 500);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        global $wpdb;
        $args = $ids;
        array_unshift($args, $viewerId);

        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT uf_passive_user_id
               FROM ' . self::table() . '
              WHERE uf_active_user_id = %d
                AND uf_follow = 1
                AND uf_passive_user_id IN (' . $placeholders . ')',
            ...$args
        ));
        return array_values(array_map('intval', $rows ?: []));
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

    /** Sample mutual-connection user ids capped per candidate — enough for a small avatar stack, not a full list. */
    private const SECOND_DEGREE_SAMPLE_CAP = 3;

    /**
     * Same candidate set as {@see getSecondDegreeCandidates}, but each
     * candidate also carries a small sample of WHICH mutual connections
     * contributed — e.g. for a "Watched by [avatar][avatar] +2 you
     * watch" UI, which a bare count can't drive. Kept as a separate
     * method rather than widening the existing one's return shape,
     * since {@see getSecondDegreeCandidates}'s `array<int,int>` contract
     * may have callers beyond this codebase's current grep.
     *
     * @return array<int, array{count: int, sample_ids: list<int>}>
     */
    public static function getSecondDegreeCandidatesWithSamples(
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

        // Same shape as getSecondDegreeCandidates, plus a GROUP_CONCAT of
        // the contributing active-side user ids (freshest edges first,
        // matching the count query's own ORDER BY) so the caller can take
        // a small prefix as the "sample" without a second round-trip.
        $sql = $wpdb->prepare(
            "SELECT a2.uf_passive_user_id AS candidate_id,
                    COUNT(DISTINCT a2.uf_active_user_id) AS mutual_count,
                    GROUP_CONCAT(DISTINCT a2.uf_active_user_id ORDER BY a2.uf_id DESC SEPARATOR ',') AS sample_ids
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

        /** @var list<array{candidate_id: string, mutual_count: string, sample_ids: string|null}>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $out = [];
        foreach (($rows ?: []) as $row) {
            $candidateId = (int) $row['candidate_id'];
            if ($candidateId <= 0) {
                continue;
            }
            $sampleIds = [];
            if ($row['sample_ids'] !== null && $row['sample_ids'] !== '') {
                foreach (explode(',', $row['sample_ids']) as $rawId) {
                    $sampleIds[] = (int) $rawId;
                    if (count($sampleIds) >= self::SECOND_DEGREE_SAMPLE_CAP) {
                        break;
                    }
                }
            }
            $out[$candidateId] = [
                'count'      => (int) $row['mutual_count'],
                'sample_ids' => $sampleIds,
            ];
        }
        return $out;
    }

    /**
     * Counts only — used for view-model headlines and badges.
     *
     * @param list<int>|null $viewerFriendIds The authors the VIEWER is a friend of, for the
     *                                      level-30 branch. null/[] = a friend of nobody, which
     *                                      DENIES level 30 to all but the listed user themselves.
     *                                      Capped at {@see self::VIEWER_FRIEND_IDS_MAX}; over the
     *                                      cap the list is emptied, never truncated.
     * @return array{following: int, followers: int}
     */
    public static function getCounts(
        int $userId,
        int $viewerId = 0,
        ?array $viewerFriendIds = null,
        bool $enforceProfileVisibility = false
    ): array {
        global $wpdb;
        $table = self::table();

        // Each count is gated on the column that holds the LISTED user,
        // so it matches the list it describes: "following" lists the
        // passive side, "followers" the active side.
        //
        // Both clauses come from the same helper the id queries use. A
        // count computed by a different rule than its list is how a
        // filtered follower list ends up advertising rows it will never
        // show.
        $friends      = $enforceProfileVisibility ? self::cleanFriendIds($viewerFriendIds) : [];
        $ingJoin      = '';
        $ingWhere     = '';
        $ingParams    = [];
        $ersJoin      = '';
        $ersWhere     = '';
        $ersParams    = [];
        if ($enforceProfileVisibility) {
            [$ingJoin, $ingWhere, $ingParams] = self::visibilityClause('uf_passive_user_id', $viewerId, $friends);
            [$ersJoin, $ersWhere, $ersParams] = self::visibilityClause('uf_active_user_id', $viewerId, $friends);
        }

        $following = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}{$ingJoin} WHERE uf_active_user_id = %d AND uf_follow = 1{$ingWhere}",
            ...array_merge([$userId], $ingParams)
        ));
        $followers = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}{$ersJoin} WHERE uf_passive_user_id = %d AND uf_follow = 1{$ersWhere}",
            ...array_merge([$userId], $ersParams)
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
