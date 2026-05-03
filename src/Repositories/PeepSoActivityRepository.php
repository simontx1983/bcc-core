<?php

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to PeepSo activity stream rows.
 *
 * Backing table: {prefix}peepso_activities, joined to wp_posts for
 * the actor / timestamp / active-state fields that PeepSo doesn't
 * persist on the activity row itself.
 *
 * PeepSo's actual schema (verified 2026-04-30):
 *   - act_id                  PK (peepso_activities)
 *   - act_owner_id            owner of the page/wall the activity lives on
 *   - act_module_id           module id (numeric for native PeepSo modules,
 *                             string for BCC-owned modules like 'review')
 *   - act_external_id         FK into wp_posts.ID (most modules) or a
 *                             module-specific sidecar table (BCC modules)
 *   - act_access              PeepSo visibility (public/friends/private)
 *   - (no act_user_id, act_time, or act_status columns)
 *
 * Derived fields (joined in by this repository):
 *   - act_user_id  ← wp_posts.post_author    (the actor)
 *   - act_time     ← wp_posts.post_date_gmt  (when the activity happened)
 *   - act_status   ← wp_posts.post_status    ('publish' = active row)
 *
 * Activities whose act_external_id doesn't resolve to a wp_post are
 * INNER-JOIN-filtered out. Today this means BCC-owned modules like
 * 'review' (sidecar id = bcc_trust_votes.id, no backing wp_post)
 * never surface here — that gap is tracked separately on the writer.
 *
 * Cursor format: opaque to client; encoded server-side as
 *   base64({"t":"<iso8601>","id":<act_id>}). Repository accepts the decoded
 *   tuple via $cursorTime + $cursorActId for keyset pagination.
 *
 * No SELECT *. No writes — PeepSo owns the write path.
 *
 * @phpstan-type ActivityRow object{
 *   act_id: int|numeric-string,
 *   act_user_id: int|numeric-string,
 *   act_owner_id: int|numeric-string,
 *   act_module_id: string,
 *   act_external_id: int|numeric-string,
 *   act_time: string,
 *   act_access: int|numeric-string,
 *   act_status: string
 * }
 */
final class PeepSoActivityRepository
{
    private const TABLE_SUFFIX = 'peepso_activities';

    /**
     * Explicit SELECT list — peepso_activities columns plus the three
     * fields synthesized from the wp_posts JOIN. Aliases keep the row
     * shape stable for the rest of the codebase (FeedItemNormalizer,
     * downstream services) so call sites don't need to know about the
     * JOIN.
     */
    private const COLUMNS = 'a.act_id, p.post_author AS act_user_id, a.act_owner_id, a.act_module_id, a.act_external_id, p.post_date_gmt AS act_time, a.act_access, p.post_status AS act_status';

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Activities chronological-newest-first, optionally filtered by author set
     * and module set. Used by ActivityFeedService for `following` and `signals`
     * scopes (the `for_you` scope adds ranking on top — out of scope here).
     *
     * @param list<int>|null $authorIds         null = no author filter; [] = no posts (returns []); non-empty list = only those authors.
     * @param list<string>|null $moduleIds      null = all modules; [] = no posts; non-empty list = only those modules.
     * @param list<int>|null $excludedAuthorIds null/[] = no exclusion; non-empty list = drop these authors. Used by the feed ranker to apply §O4.1 caution/risky shadow-limit without coupling bcc-core to bcc-trust's tier concept.
     * @param list<int>|null $excludedActIds    null/[] = no exclusion; non-empty list = drop these specific act_ids. Used by §K1 Phase C moderation hide so individual posts can be suppressed without touching their author or module. Same coupling-avoidance pattern as $excludedAuthorIds — bcc-core stays unaware of WHY the ids are hidden.
     * @return list<ActivityRow>
     * @phpstan-return list<ActivityRow>
     */
    public static function getActivities(
        ?array $authorIds = null,
        ?array $moduleIds = null,
        ?string $cursorTime = null,
        ?int $cursorActId = null,
        int $limit = 20,
        ?array $excludedAuthorIds = null,
        ?array $excludedActIds = null
    ): array {
        global $wpdb;

        // Empty filter set means "no possible matches" — short-circuit before
        // building a SQL IN () clause that would otherwise be invalid.
        if ($authorIds === [] || $moduleIds === []) {
            return [];
        }

        // INNER JOIN wp_posts: actor + timestamp + active-state all live
        // there. post_status='publish' replaces the historical
        // (nonexistent) act_status filter — soft-deleted PeepSo activities
        // are reflected via post_status changes (trash, draft).
        $where  = ["p.post_status = 'publish'"];
        $params = [];

        if ($authorIds !== null) {
            $placeholders = implode(',', array_fill(0, count($authorIds), '%d'));
            $where[]      = "p.post_author IN ({$placeholders})";
            foreach ($authorIds as $id) {
                $params[] = (int) $id;
            }
        }

        if ($excludedAuthorIds !== null && $excludedAuthorIds !== []) {
            $placeholders = implode(',', array_fill(0, count($excludedAuthorIds), '%d'));
            $where[]      = "p.post_author NOT IN ({$placeholders})";
            foreach ($excludedAuthorIds as $id) {
                $params[] = (int) $id;
            }
        }

        if ($excludedActIds !== null && $excludedActIds !== []) {
            $placeholders = implode(',', array_fill(0, count($excludedActIds), '%d'));
            $where[]      = "a.act_id NOT IN ({$placeholders})";
            foreach ($excludedActIds as $id) {
                $params[] = (int) $id;
            }
        }

        if ($moduleIds !== null) {
            $placeholders = implode(',', array_fill(0, count($moduleIds), '%s'));
            $where[]      = "a.act_module_id IN ({$placeholders})";
            foreach ($moduleIds as $m) {
                $params[] = (string) $m;
            }
        }

        // Keyset pagination: (post_date_gmt DESC, act_id DESC) with strict tiebreak.
        if ($cursorTime !== null && $cursorActId !== null) {
            $where[]  = '(p.post_date_gmt < %s OR (p.post_date_gmt = %s AND a.act_id < %d))';
            $params[] = $cursorTime;
            $params[] = $cursorTime;
            $params[] = $cursorActId;
        }

        $params[] = $limit;

        $sql = 'SELECT ' . self::COLUMNS . '
                  FROM ' . self::table() . ' a
                  INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY p.post_date_gmt DESC, a.act_id DESC
                 LIMIT %d';

        /** @phpstan-var list<ActivityRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return $rows ?: [];
    }

    /**
     * @phpstan-return ActivityRow|null
     */
    public static function getById(int $actId): ?object
    {
        global $wpdb;
        /** @phpstan-var ActivityRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM ' . self::table() . ' a
               INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
              WHERE a.act_id = %d
              LIMIT 1',
            $actId
        ));
        return $row ?: null;
    }

    /**
     * Network-percentile rollup for §O3.1 — where does this user rank
     * in raw activity count vs. all active users in the same window?
     *
     * Returns null when:
     *   - the user themselves has zero activity in the window
     *   - the active-user pool is too small (< MIN_PEER_POOL) to make
     *     percentile bucketing meaningful (avoids "Top 50%" against
     *     a 2-person sample)
     *
     * Three queries: my count, count of users strictly ahead, total
     * active. For V1 demo volume that's far cheaper than a windowed
     * single-query rank (and stays compatible with MySQL versions
     * without window-function support).
     *
     * `percentile_from_top` is an integer where 1 = best, 100 = worst.
     * The caller can format it however (e.g. "Top 5%", "Top 25%").
     *
     * @return array{my_count: int, total_active: int, percentile_from_top: int}|null
     */
    public static function getNetworkPercentile(int $userId, string $sinceMysql): ?array
    {
        if ($userId <= 0 || $sinceMysql === '') {
            return null;
        }

        global $wpdb;
        $table = self::table();

        $myCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} a
              INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
              WHERE p.post_author    = %d
                AND p.post_status    = 'publish'
                AND p.post_date_gmt >= %s",
            $userId,
            $sinceMysql
        ));

        if ($myCount === 0) {
            return null;
        }

        // Distinct active users in the window.
        $totalActive = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.post_author) FROM {$table} a
              INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
              WHERE p.post_status    = 'publish'
                AND p.post_date_gmt >= %s",
            $sinceMysql
        ));

        if ($totalActive < self::MIN_PEER_POOL) {
            return null;
        }

        // Users with strictly more activity than the viewer. Bounded
        // by the active-user pool above; the inner GROUP BY is what
        // makes this scale linearly with active users (not events).
        $usersAhead = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT p.post_author AS uid, COUNT(*) AS cnt
                  FROM {$table} a
                  INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
                 WHERE p.post_status    = 'publish'
                   AND p.post_date_gmt >= %s
                 GROUP BY p.post_author
                HAVING cnt > %d
            ) AS ahead",
            $sinceMysql,
            $myCount
        ));

        // Position 1 = best. Percentile = position / total * 100,
        // rounded to int.
        $position = $usersAhead + 1;
        $pct = (int) max(1, min(100, (int) round(($position / $totalActive) * 100)));

        return [
            'my_count'             => $myCount,
            'total_active'         => $totalActive,
            'percentile_from_top'  => $pct,
        ];
    }

    /**
     * Floor for the §O3.1 percentile sample size — anything smaller
     * makes "Top X%" labels meaningless ("Top 50%" against a 2-user
     * pool is not a flex). Hidden when the floor isn't met.
     */
    private const MIN_PEER_POOL = 5;

    /**
     * Aggregate one user's activities into a sparse day-keyed map.
     * Used by the §4.4 shift-log endpoint to render the 52-week grid.
     *
     * Returns only days with at least one activity; the consuming
     * service is responsible for backfilling empty days.
     *
     * GROUP_CONCAT for kinds is bounded by MySQL's
     * group_concat_max_len default (1024 chars) — sufficient for ~50
     * distinct modules per day. Activity past that limit is dropped
     * from the kinds list (count remains accurate).
     *
     * @return array<string, array{count: int, kinds: list<string>}>
     *   keyed by 'YYYY-MM-DD' UTC
     */
    public static function aggregateByDay(int $userId, string $sinceMysql, int $maxDays = 400): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT DATE(p.post_date_gmt) AS day,
                    COUNT(*) AS day_count,
                    GROUP_CONCAT(DISTINCT a.act_module_id) AS modules
               FROM ' . self::table() . ' a
               INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
              WHERE p.post_author    = %d
                AND p.post_status    = \'publish\'
                AND p.post_date_gmt >= %s
              GROUP BY DATE(p.post_date_gmt)
              ORDER BY day DESC
              LIMIT %d',
            $userId,
            $sinceMysql,
            $maxDays
        );

        /** @var list<object{day: string, day_count: numeric-string, modules: string|null}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $map = [];
        foreach ($rows ?: [] as $row) {
            $kinds = [];
            if (is_string($row->modules) && $row->modules !== '') {
                $kinds = array_values(array_filter(
                    explode(',', $row->modules),
                    static fn(string $v): bool => $v !== ''
                ));
            }
            $map[$row->day] = [
                'count' => (int) $row->day_count,
                'kinds' => $kinds,
            ];
        }
        return $map;
    }

    /**
     * Total activity count per peepso module for a user since the
     * given timestamp. Drives the §4.4 `totals_by_kind` field.
     *
     * NOTE: this returns peepso_activities module names — reaction-
     * derived kinds (`vouch`, `stand_behind`) come from
     * peepso_reactions, NOT this query. A separate aggregator will
     * merge them in when the reaction service lands.
     *
     * @return array<string, int> module_id => count
     */
    public static function aggregateByModule(int $userId, string $sinceMysql): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT a.act_module_id AS module, COUNT(*) AS module_count
               FROM ' . self::table() . ' a
               INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
              WHERE p.post_author    = %d
                AND p.post_status    = \'publish\'
                AND p.post_date_gmt >= %s
              GROUP BY a.act_module_id
              LIMIT 50',
            $userId,
            $sinceMysql
        );

        /** @var list<object{module: string, module_count: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[$row->module] = (int) $row->module_count;
        }
        return $map;
    }
}
