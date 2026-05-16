<?php

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to PeepSo page ownership + categorization.
 *
 * Backing tables:
 *   - {prefix}peepso_page_members  (pm_user_id, pm_page_id, pm_user_status)
 *   - {prefix}peepso_page_categories (pm_page_id, pm_cat_id)
 *   - {prefix}posts                (post_type='peepso-page-cat': category posts)
 *
 * The ownership predicate is `pm_user_status = 'member_owner'` — the
 * canonical "this user is the page's owner" flag PeepSo uses internally.
 * The category labels live as a sibling CPT (`peepso-page-cat`); the
 * join table associates pages with one or more categories.
 *
 * Categorization caveat: PeepSo pages are *tagged*, not *typed* — a
 * single page can carry multiple categories. Typed counts here treat
 * each tag independently, so the sum across types may exceed
 * `owned_pages_count` for a user whose pages are multi-categorized.
 * This matches the directory card semantics where each badge counts
 * the user's "presence" in that type.
 *
 * No SELECT *. Queries bounded by user_id IN (...) on caller-paginated
 * sets. No writes — PeepSo owns the write path.
 *
 * Same-name sibling: this class shares its short name with
 * {@see \BCC\PeepSo\Repositories\PeepSoPageRepository}, which reads the
 * shadow-CPT category-relation rows. Different responsibility — that
 * class supports the shadow-CPT sync layer; this class is the trust
 * read-side for page ownership and categorization. Do not collapse —
 * see docs/pattern-registry.md "Same-name-different-class index".
 */
final class PeepSoPageRepository
{
    /**
     * Map of `peepso-page-cat` post_name → canonical type slug emitted
     * on the wire. Both the existing typo'd slug (`vaildators`) and the
     * corrected slug (`validators`) are accepted so a future admin
     * cleanup of the category slug doesn't break the mapping.
     *
     * Adding a new canonical type requires (a) a new entry here, (b)
     * a corresponding key in the `getOwnedPageTypeCountsForUsers`
     * default-zero record, (c) a contract amendment to MemberSummary
     * `owned_pages_by_type`.
     */
    private const CATEGORY_SLUG_TO_TYPE = [
        'vaildators'   => 'validator',  // existing PeepSo slug — note typo, preserved
        'validators'   => 'validator',
        'nft-creators' => 'nft',
        'nft-creator'  => 'nft',
        'builders'     => 'project',
        'builder'      => 'project',
        'daos'         => 'dao',
        'dao'          => 'dao',
    ];

    /**
     * Default-zero record shape returned for every requested user_id,
     * even when they own no pages. Frontends key on this shape and
     * render a typed badge per non-zero entry.
     */
    private const ZERO_TYPE_COUNTS = [
        'validator' => 0,
        'project'   => 0,
        'nft'       => 0,
        'dao'       => 0,
    ];

    /**
     * Reverse of CATEGORY_SLUG_TO_TYPE — for a canonical type slug,
     * the set of PeepSo category slugs that count as that type. Used
     * by the directory filter (`/members?type=...`) to translate a
     * single user-facing filter value into the SQL IN clause.
     */
    private const TYPE_TO_CATEGORY_SLUGS = [
        'validator' => ['vaildators', 'validators'],
        'project'   => ['builders', 'builder'],
        'nft'       => ['nft-creators', 'nft-creator'],
        'dao'       => ['daos', 'dao'],
    ];

    /**
     * Whether `$type` is a canonical type slug accepted by the
     * directory filter. Endpoint validators short-circuit on false.
     */
    public static function isValidType(string $type): bool
    {
        return isset(self::TYPE_TO_CATEGORY_SLUGS[$type]);
    }

    /**
     * Batched typed-count lookup: for a set of user_ids, returns the
     * count of `member_owner` pages per canonical type. Pages without
     * a recognized category slug don't contribute to any bucket (they
     * still count toward `getOwnedPageCountsForUsers` though).
     *
     * Empty `$userIds` short-circuits — no SQL.
     *
     * @param int[] $userIds Bounded by caller (directory `per_page`
     *                       cap, e.g. 50). The IN clause scales
     *                       linearly with the bound.
     * @return array<int, array{validator: int, project: int, nft: int, dao: int}>
     */
    public static function getOwnedPageTypeCountsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // Sanitize + dedupe — same pattern as the other batched repo
        // methods. Reject zero/negative ids before SQL.
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
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        // Single GROUP BY scan: ownership rows joined to category-tag
        // rows joined to the category post (for slug → type mapping).
        // Rows where a page has no category fall out via the inner
        // join — that's the documented "untagged pages don't count
        // per-type" semantics.
        /** @var list<array{user_id: string, type_slug: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.pm_user_id AS user_id,
                        cat.post_name AS type_slug,
                        COUNT(*) AS c
                   FROM {$wpdb->prefix}peepso_page_members pm
                   INNER JOIN {$wpdb->prefix}peepso_page_categories pc
                           ON pc.pm_page_id = pm.pm_page_id
                   INNER JOIN {$wpdb->posts} cat
                           ON cat.ID = pc.pm_cat_id
                          AND cat.post_type = 'peepso-page-cat'
                          AND cat.post_status = 'publish'
                  WHERE pm.pm_user_id IN ({$placeholders})
                    AND pm.pm_user_status = 'member_owner'
                  GROUP BY pm.pm_user_id, cat.post_name",
                ...$idList
            ),
            ARRAY_A
        );

        // Initialize every requested user with the default-zero shape.
        // Callers can read `result[$userId]` without a presence guard.
        $out = [];
        foreach ($idList as $id) {
            $out[$id] = self::ZERO_TYPE_COUNTS;
        }

        foreach (($rows ?: []) as $row) {
            $userId   = (int) $row['user_id'];
            $slug     = (string) $row['type_slug'];
            $count    = (int) $row['c'];
            $typeKey  = self::CATEGORY_SLUG_TO_TYPE[$slug] ?? null;
            if ($typeKey === null) {
                // Category slug we don't recognize — skip silently.
                // Real-world admin curation may introduce new
                // categories; surfacing them requires a contract bump,
                // not a runtime fallback bucket.
                continue;
            }
            // Same canonical type can be hit twice if PeepSo has both
            // the typo'd slug AND the corrected slug active (e.g.,
            // mid-rename). Sum into the bucket either way.
            $out[$userId][$typeKey] += $count;
        }

        // Materialize the declared `array{validator,project,nft,dao}`
        // struct shape with explicit literal keys so PHPStan can prove
        // the return type. Variable-key mutation in the loop above
        // widens the inferred shape to `array<string,int>`; this final
        // rewrite collapses it back to the contract — every $idList
        // entry was initialised with ZERO_TYPE_COUNTS so the `??` is a
        // belt-and-braces no-op at runtime.
        $result = [];
        foreach ($out as $id => $counts) {
            $result[$id] = [
                'validator' => $counts['validator'] ?? 0,
                'project'   => $counts['project']   ?? 0,
                'nft'       => $counts['nft']       ?? 0,
                'dao'       => $counts['dao']       ?? 0,
            ];
        }
        return $result;
    }

    /**
     * IDs of users who own at least one `member_owner` page tagged
     * with the canonical type. Drives the `/members?type=...` filter
     * — the endpoint pre-resolves the matching user_id list and
     * passes it to `WP_User_Query` via `include`.
     *
     * Unknown types short-circuit to an empty list so the endpoint's
     * fallback behavior (return no rows) is the safer of two failure
     * modes when a future client passes a yet-to-be-released type.
     *
     * Single SQL: `peepso_page_members` JOIN `peepso_page_categories`
     * JOIN `posts(peepso-page-cat)`, GROUP BY user. The `DISTINCT` on
     * the user_id is implicit because of the GROUP BY; the result is
     * the set of users with ≥1 owned page of the requested type.
     *
     * @param string $type Canonical type slug (validate via `isValidType`).
     * @return list<int>
     */
    public static function getUserIdsOwningPagesOfType(string $type): array
    {
        $catSlugs = self::TYPE_TO_CATEGORY_SLUGS[$type] ?? null;
        if ($catSlugs === null) {
            return [];
        }

        global $wpdb;
        $catPlaceholders = implode(',', array_fill(0, count($catSlugs), '%s'));

        /** @var list<array{user_id: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.pm_user_id AS user_id
                   FROM {$wpdb->prefix}peepso_page_members pm
                   INNER JOIN {$wpdb->prefix}peepso_page_categories pc
                           ON pc.pm_page_id = pm.pm_page_id
                   INNER JOIN {$wpdb->posts} cat
                           ON cat.ID = pc.pm_cat_id
                          AND cat.post_type = 'peepso-page-cat'
                          AND cat.post_status = 'publish'
                  WHERE pm.pm_user_status = 'member_owner'
                    AND cat.post_name IN ({$catPlaceholders})
                  GROUP BY pm.pm_user_id",
                ...$catSlugs
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $userId = (int) $row['user_id'];
            if ($userId > 0) {
                $out[] = $userId;
            }
        }
        return $out;
    }

    /**
     * Global count of distinct users with ≥1 `member_owner` page per
     * canonical type. Drives the directory's filter-chip counts
     * (`VALIDATORS · 5`) — semantically *global*, independent of any
     * active `q` or `type` filter, so the chip numbers don't shift
     * around as a viewer types in the search box.
     *
     * Single SQL, then PHP-side aggregation through the canonical
     * slug-to-type map so a user counted under both the typo'd slug
     * (`vaildators`) and the corrected slug (`validators`) collapses
     * into a single user under `validator` rather than double-counting.
     *
     * @return array{validator: int, project: int, nft: int, dao: int}
     */
    public static function getGlobalOwnedPageUserCountsByType(): array
    {
        global $wpdb;

        // For each (user_id, slug) pair where the user owns ≥1 page
        // tagged with that PeepSo category, surface one row. The
        // GROUP BY collapses duplicate (user, slug) pairs (a user
        // owning multiple pages tagged with the same category appears
        // once). PHP then does the slug→type mapping + dedup-by-user.
        /** @var list<array{user_id: string, slug: string}> $rows */
        $rows = $wpdb->get_results(
            "SELECT pm.pm_user_id AS user_id, cat.post_name AS slug
               FROM {$wpdb->prefix}peepso_page_members pm
               INNER JOIN {$wpdb->prefix}peepso_page_categories pc
                       ON pc.pm_page_id = pm.pm_page_id
               INNER JOIN {$wpdb->posts} cat
                       ON cat.ID = pc.pm_cat_id
                      AND cat.post_type = 'peepso-page-cat'
                      AND cat.post_status = 'publish'
              WHERE pm.pm_user_status = 'member_owner'
              GROUP BY pm.pm_user_id, cat.post_name",
            ARRAY_A
        );

        // Per-type set of distinct user_ids; key-on-userId dedupes the
        // multi-slug-same-type case for free. Final count = set size.
        $byType = [
            'validator' => [],
            'project'   => [],
            'nft'       => [],
            'dao'       => [],
        ];
        foreach (($rows ?: []) as $row) {
            $slug   = (string) $row['slug'];
            $userId = (int) $row['user_id'];
            $type   = self::CATEGORY_SLUG_TO_TYPE[$slug] ?? null;
            if ($type === null || $userId <= 0) {
                continue;
            }
            $byType[$type][$userId] = true;
        }

        return [
            'validator' => count($byType['validator']),
            'project'   => count($byType['project']),
            'nft'       => count($byType['nft']),
            'dao'       => count($byType['dao']),
        ];
    }

    /**
     * Reverse owner-to-pages lookup: given a set of user_ids, return
     * the `member_owner` page_ids they own. Caller-paginated set;
     * result is capped defensively (default 500).
     *
     * Drives §O2.1 EXTERNAL-slot resolver in HighlightsService, which
     * needs "pages owned by everyone the viewer follows" before
     * querying score_events on those pages. The viewer's watchlist is
     * user-keyed; the score-event stream is page-keyed — this method
     * is the bridge.
     *
     * Empty `$userIds` short-circuits (no SQL). The ordering by
     * `pm_id DESC` keeps newest memberships first so the most recently
     * established ownership pages surface earlier when the caller's
     * downstream LIMIT clips the list.
     *
     * @param list<int> $userIds Bounded by caller (typical 200 follows
     *                           from PeepSoFollowerRepository::getFollowing).
     * @param int       $limit   Defensive cap on returned page_ids.
     * @return list<int>
     */
    public static function getPageIdsOwnedByUsers(array $userIds, int $limit = 500): array
    {
        if ($userIds === []) {
            return [];
        }

        $clean = [];
        foreach ($userIds as $id) {
            $i = (int) $id;
            if ($i > 0) {
                $clean[$i] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);

        $cappedLimit = min(max($limit, 1), 1000);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        /** @var list<array{page_id: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm_page_id AS page_id
                   FROM {$wpdb->prefix}peepso_page_members
                  WHERE pm_user_id IN ({$placeholders})
                    AND pm_user_status = 'member_owner'
                  ORDER BY pm_id DESC
                  LIMIT %d",
                ...array_merge($idList, [$cappedLimit])
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $pid = (int) $row['page_id'];
            if ($pid > 0) {
                $out[] = $pid;
            }
        }
        return $out;
    }
}
