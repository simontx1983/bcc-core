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
     * @param int|null $onlyForGroupId          null = no group filter; positive int = only activities whose backing wp_post lives in this PeepSo group (joined via `peepso_group_id` post-meta). Used by /bcc/v1/groups/{id}/feed for the group-scoped feed; bcc-trust's caller handles the privacy gate before invoking this.
     * @param list<int>|null $excludedGroupIds  INERT for global-feed inclusion as of the per-post-visibility phase. The non-group (global) feed now syndicates a group-tagged post ONLY when it carries `_bcc_post_visibility = 'public_all'` post-meta; non-group posts (no `peepso_group_id`) are always included. The old "(non-open groups) - (viewer memberships)" exclusion-list no longer drives the WHERE — the per-post visibility gate supersedes it. The param is retained in the signature so existing callers (which still pass a computed list) keep working without churn, but it is intentionally unused in the query. Removed cleanly when the call sites stop computing the list.
     * @param list<string>|null $groupVisibilityIn Per-post visibility allow-list for the GROUP-SCOPED path. Only applies when non-null AND `$onlyForGroupId !== null`. Adds an INNER JOIN on `_bcc_post_visibility` post-meta restricting to the given values (bcc-trust passes ['public_group','public_all'] for a non-member teaser feed). INNER (not LEFT) JOIN: posts with absent `_bcc_post_visibility` meta are EXCLUDED for non-members — absent ⇒ members_only ⇒ hidden — which is the security invariant. When null (member read) the join is omitted so members see every post in the group. Ignored on the global path (`$onlyForGroupId === null`), which has its own 'public_all' gate.
     * @param ?string $hashtag Optional hashtag filter (tag text WITHOUT the leading '#'). When a non-empty string, restricts the candidate set to activities whose backing wp_post.post_content contains the tag via `p.post_content LIKE '%#<tag>%'`. This mirrors how PeepSo itself associates a post with a hashtag (substring match on the rendered '#tag' token), so the filtered set is consistent with PeepSo's own `ht_count` accounting. null/'' = no hashtag filter. The filter is a pure narrowing predicate — it can never WIDEN the candidate set, so every other visibility / exclusion clause still applies in full.
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
        ?array $excludedActIds = null,
        ?int $onlyForGroupId = null,
        ?array $excludedGroupIds = null,
        ?array $groupVisibilityIn = null,
        ?string $hashtag = null
    ): array {
        global $wpdb;

        // Empty filter set means "no possible matches" — short-circuit before
        // building a SQL IN () clause that would otherwise be invalid.
        if ($authorIds === [] || $moduleIds === []) {
            return [];
        }
        if ($onlyForGroupId !== null && $onlyForGroupId <= 0) {
            return [];
        }

        // INNER JOIN wp_posts: actor + timestamp + active-state all live
        // there. post_status='publish' replaces the historical
        // (nonexistent) act_status filter — soft-deleted PeepSo activities
        // are reflected via post_status changes (trash, draft).
        //
        // Exclude comment activity rows. PeepSo writes a peepso_activities
        // row for EVERY comment (act_external_id = the comment's own
        // wp_post, act_comment_object_id = the parent post's id). Without
        // this guard those rows INNER JOIN a published wp_post and leak
        // into the feed as if they were top-level posts. This is the exact
        // inverse of the `act_comment_object_id > 0` predicate every
        // CommentRepository read uses to SELECT comments. NULL-safe in case
        // a non-comment row stores NULL rather than 0.
        $where  = [
            "p.post_status = 'publish'",
            '(a.act_comment_object_id = 0 OR a.act_comment_object_id IS NULL)',
        ];
        // $wpdb->prepare binds placeholders strictly left-to-right against
        // the argument list. JOIN clauses render BEFORE the WHERE clause in
        // the final SQL string, so any JOIN-bound params MUST precede the
        // WHERE-bound params in the argument list. We collect JOIN params in
        // a separate bucket and prepend them at the end (see $params merge
        // before prepare()).
        $joinParams = [];
        $params     = [];

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

        // Hashtag filter — narrow the candidate set to posts whose body
        // contains the '#tag' token. Substring LIKE on post_content
        // mirrors how PeepSo associates a post with a hashtag (and how it
        // derives ht_count), so the slice stays consistent with PeepSo's
        // own counter. esc_like neutralizes %/_ inside the tag so a tag
        // like "c_plus" doesn't become a wildcard. This is a WHERE param:
        // it renders AFTER any JOIN params and BEFORE the LIMIT, matching
        // the strict left-to-right prepare ordering — appended to $params
        // (not $joinParams) at the point its placeholder appears.
        if (is_string($hashtag) && $hashtag !== '') {
            $where[]  = 'p.post_content LIKE %s';
            $params[] = '%#' . $wpdb->esc_like($hashtag) . '%';
        }

        // Group scope: filter to activities whose backing wp_post lives in
        // a specific PeepSo group. PeepSo writes `peepso_group_id` post-meta
        // when a status / photo / GIF post is created inside a group; the
        // INNER JOIN here is the cheapest way to scope the candidate set
        // server-side (uses postmeta's (post_id, meta_key) index, which
        // is one of WP's tightest). The caller (bcc-trust GroupsService)
        // is responsible for the privacy gate — secret/closed groups must
        // never reach this filter for non-members.
        $groupJoin = '';
        if ($onlyForGroupId !== null) {
            $groupJoin = ' INNER JOIN ' . $wpdb->postmeta . ' gm_pm
                              ON gm_pm.post_id   = p.ID
                             AND gm_pm.meta_key  = \'peepso_group_id\'
                             AND gm_pm.meta_value = %s ';
            $joinParams[] = (string) $onlyForGroupId;
        }

        // Per-post visibility scope (group-scoped path only).
        //
        // When a non-member reads the group teaser, bcc-trust passes
        // $groupVisibilityIn = ['public_group','public_all']. We add an
        // INNER JOIN on `_bcc_post_visibility` restricting to those values.
        //
        // INNER (not LEFT) JOIN is the security invariant: a post with NO
        // `_bcc_post_visibility` meta row (absent ⇒ members_only ⇒ hidden)
        // produces no joined row and is therefore EXCLUDED — members_only
        // and absent-meta posts can never reach a non-member. A distinct
        // alias (`vis_in_pm`) avoids colliding with the global-feed
        // `vis_pm` LEFT JOIN above.
        //
        // Member reads pass $groupVisibilityIn = null → the join is omitted
        // → members see every post including members_only. Only meaningful
        // on the group-scoped path; the guard requires $onlyForGroupId.
        $visibilityInJoin = '';
        if ($groupVisibilityIn !== null && $groupVisibilityIn !== [] && $onlyForGroupId !== null) {
            $visPlaceholders  = implode(',', array_fill(0, count($groupVisibilityIn), '%s'));
            $visibilityInJoin = ' INNER JOIN ' . $wpdb->postmeta . ' vis_in_pm
                                     ON vis_in_pm.post_id   = p.ID
                                    AND vis_in_pm.meta_key  = \'_bcc_post_visibility\'
                                    AND vis_in_pm.meta_value IN (' . $visPlaceholders . ') ';
            foreach ($groupVisibilityIn as $vis) {
                $joinParams[] = (string) $vis;
            }
        }

        // Global-feed visibility gate (non-group path only).
        //
        // Rule: a group-tagged post (one carrying `peepso_group_id`
        // post-meta) appears in the GLOBAL feed ONLY when it also carries
        // `_bcc_post_visibility = 'public_all'`. Posts NOT inside any
        // group (no `peepso_group_id` meta row at all) are preserved
        // unconditionally.
        //
        // Two LEFT JOINs with NULL-passthrough:
        //   - gx_pm  → the `peepso_group_id` marker. NULL ⇒ non-group post.
        //   - vis_pm → the `_bcc_post_visibility` marker. NULL ⇒ treat as
        //              members_only (no migration; absent meta is the
        //              conservative default), so a group post with no
        //              visibility meta is correctly excluded.
        //
        // WHERE: (gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')
        //   - non-group post                → gx_pm NULL → included.
        //   - group post, public_all        → vis_pm matches → included.
        //   - group post, members_only/etc. → both clauses false → dropped.
        //
        // This supersedes the old `$excludedGroupIds` NOT-IN exclusion —
        // the per-post visibility marker is the single source of truth for
        // global syndication now, so the membership-derived exclude list
        // is no longer consulted (the param is kept inert in the signature
        // for caller compatibility). Skipped entirely when `$onlyForGroupId`
        // is set — that INNER JOIN already constrains to one group and the
        // group-scoped read gate (Phase 2/3) is unchanged here.
        $groupVisibilityJoin = '';
        if ($onlyForGroupId === null) {
            $groupVisibilityJoin = ' LEFT JOIN ' . $wpdb->postmeta . ' gx_pm
                                        ON gx_pm.post_id  = p.ID
                                       AND gx_pm.meta_key = \'peepso_group_id\'
                                     LEFT JOIN ' . $wpdb->postmeta . ' vis_pm
                                        ON vis_pm.post_id  = p.ID
                                       AND vis_pm.meta_key = \'_bcc_post_visibility\' ';
            // meta_value is varchar — compare against the literal string.
            $where[] = "(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')";
        }

        // Final argument order mirrors placeholder appearance in $sql:
        // JOIN params (gm_pm, then vis_in_pm) → WHERE params → LIMIT.
        $allParams = array_merge($joinParams, $params, [$limit]);

        $sql = 'SELECT ' . self::COLUMNS . '
                  FROM ' . self::table() . ' a
                  INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
                  ' . $groupJoin . '
                  ' . $visibilityInJoin . '
                  ' . $groupVisibilityJoin . '
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY p.post_date_gmt DESC, a.act_id DESC
                 LIMIT %d';

        /** @phpstan-var list<ActivityRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$allParams));
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
     * The post author of the activity's backing wp_post — i.e. who
     * authored the content the act points at. Returns 0 when the act
     * is missing (deleted post / stale event). COLUMNS already exposes
     * `p.post_author AS act_user_id`, so this is a thin wrapper over
     * getById(). Shared by the Slice 3 vouch pipeline and
     * NotificationDispatcher's act→author resolution.
     */
    public static function getAuthorId(int $actId): int
    {
        $row = self::getById($actId);
        return $row ? (int) ($row->act_user_id ?? 0) : 0;
    }

    /**
     * Slim single-column lookup for callers that only need the
     * activity's `act_module_id` (no wp_posts JOIN). Used by the
     * v1.5 reactions endpoint to derive the post's grammar — the
     * full row is overkill when only one column matters.
     */
    public static function getModuleIdByActId(int $actId): ?string
    {
        if ($actId <= 0) {
            return null;
        }
        global $wpdb;
        $module = $wpdb->get_var($wpdb->prepare(
            'SELECT act_module_id
               FROM ' . self::table() . '
              WHERE act_id = %d
              LIMIT 1',
            $actId
        ));
        return is_string($module) && $module !== '' ? $module : null;
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

    /**
     * Lifetime count of `act_module_id = 'blog'` activities authored by
     * $userId. Joins against wp_posts so deleted / unpublished blog
     * posts don't inflate the count — a published-only count matches
     * what the /u/:handle/blog tab actually shows.
     *
     * Returns 0 for invalid userId or zero rows.
     */
    public static function countBlogsByAuthor(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT COUNT(*)
               FROM ' . self::table() . ' a
               INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
              WHERE p.post_author     = %d
                AND a.act_module_id   = \'blog\'
                AND p.post_status     = \'publish\'',
            $userId
        );

        return (int) $wpdb->get_var($sql);
    }
}
