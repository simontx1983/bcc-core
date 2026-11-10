<?php

namespace BCC\Core\Repositories;

use BCC\Core\Feed\FeedItemNormalizer;

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
     * Post-meta marker tying a status post to the page it was written for.
     * bcc-trust stamps it after its write gate passes; the marker is the only
     * thing the page-scoped read filters on, so a post stays on its page
     * whatever happens to the author's standing later.
     */
    public const PAGE_TARGET_META = '_bcc_page_id';

    /**
     * Ceiling on `$restrictedGroupIds`, for §3 bounded queries.
     *
     * The deny list is "non-public groups minus the viewer's memberships",
     * so for an ANONYMOUS viewer it is every non-public group on the site
     * and has no natural ceiling. Past this bound the list is refused and
     * the global `public_all` gate is kept instead, which hides strictly
     * MORE than the deny list would — degrading closed, never open. The
     * value matches the 1000-row membership lookup the caller's own
     * restricted-group resolver is already capped at, so a list that
     * overflows here was built from a truncated membership set anyway.
     */
    public const RESTRICTED_GROUP_IDS_MAX = 1000;

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
     * @param ?string $hallFeed Ranked-Hall channel selector for the GROUP-SCOPED path only (Rank Phase 7, §21.3). Only applies when `$onlyForGroupId !== null`; ignored (no-op) on every global path. 'ranked' → INNER JOIN on `_bcc_ranked_feed = '1'` post-meta so ONLY ranked-channel posts surface. 'main' → LEFT JOIN + IS NULL anti-join so ranked-channel posts are EXCLUDED from the group's main feed. null = no channel filter (legacy callers unchanged). bcc-trust's FeedRankingService passes 'ranked'/'main' for /groups/{id}/feed; the write-side gate (Journeyman+ AND Neutral+ AND Hall member) lives in bcc-trust — bcc-core stays unaware of the policy, same coupling-avoidance pattern as the exclusion channels above.
     * @param int|null $onlyForPageId           null = no page filter; positive int = only activities whose backing wp_post carries {@see self::PAGE_TARGET_META} post-meta matching this page; non-positive = no posts (returns []). Used by /bcc/v1/pages/{id}/feed. INNER JOIN on the marker alone: the author's current standing is deliberately not consulted here. Pure narrowing — the global-feed visibility gate below still applies, since this path leaves `$onlyForGroupId` null.
     * @param list<int>|null $restrictedGroupIds ENFORCED group exclusion — drops any activity whose backing wp_post is in one of these PeepSo groups. null = no list supplied; [] = supplied and empty ("restricted from nothing"); the two are NOT interchangeable, see `$groupGateUsesRestrictedListOnly`. ⚠ Deliberately NOT the same thing as `$excludedGroupIds` above, which is INERT: that one is a no-op kept for caller compatibility, so an exclusion passed there filters NOTHING. Use THIS param when a read must actually hide group content — the profile Blog tab (`/users/{handle}/blog`) passes the viewer's restricted-group list so a blog written inside a group the viewer may not see cannot surface on a public profile. Enforced as a per-post `NOT EXISTS` subquery, NOT a join: a post carrying two `peepso_group_id` rows (one restricted, one allowed) is DROPPED, where a join plus `NOT IN` would have let the allowed row satisfy the predicate and `SELECT DISTINCT` would have collapsed it back in. A post in no group always survives. Ids are de-duplicated and integer-cast before binding. Capped at {@see self::RESTRICTED_GROUP_IDS_MAX} — a longer list is REFUSED and the global gate kept, never silently truncated. bcc-core stays unaware of WHY a group is restricted — bcc-trust computes the list, same coupling-avoidance pattern as `$excludedAuthorIds`.
     * @param bool $groupGateUsesRestrictedListOnly Replaces the global-feed group gate with the `$restrictedGroupIds` deny list, for the ONE read whose visibility rule is "can the viewer see the group" rather than "did the author syndicate this globally". false (DEFAULT, every pre-existing caller) = unchanged: a group-tagged post reaches a non-group-scoped read only when it carries `_bcc_post_visibility = 'public_all'`. true = that requirement is dropped and group access is decided ENTIRELY by `$restrictedGroupIds`; pass it from the profile Blog tab, where a `members_only` blog MUST still be visible to the group's own members. ⚠ FAIL-CLOSED: true is HONOURED ONLY when `$restrictedGroupIds` is non-null AND within the cap. Passing true with null, or with an over-long list, keeps the `public_all` gate — so "gate off AND nothing filtering" is unreachable, and a caller whose membership lookup degraded cannot turn this into a leak by passing nothing. An explicit `[]` IS honoured and does mean "no group is restricted for this viewer"; that is correct only on a read already scoped to the viewer's own authorship, which is why the degraded caller must pass null rather than `[]`. Ignored when `$onlyForGroupId !== null`, which has its own gate.
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
        ?string $hashtag = null,
        ?string $hallFeed = null,
        ?int $onlyForPageId = null,
        ?array $restrictedGroupIds = null,
        bool $groupGateUsesRestrictedListOnly = false
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
        if ($onlyForPageId !== null && $onlyForPageId <= 0) {
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

        // Module allowlist gate (fail-closed). A null $moduleIds
        // historically meant "no module filter", which let PeepSo-native
        // non-post modules (6 DMs, 8 group-join stream, 9 page-wall,
        // 30 polls, 111 backgrounds, 6661 peepso-blog) and any unknown
        // module leak into the public feed. The default now narrows to the
        // canonical public-feed allowlist ({@see FeedItemNormalizer::publicFeedModuleIds()}
        // — status/photo + BCC 200-range). An explicit $moduleIds list
        // (e.g. the signals scope, or the blog tab's ['blog']) is honored
        // verbatim, as-is, with the historical string binding.
        if ($moduleIds === null) {
            $allowedModuleIds = FeedItemNormalizer::publicFeedModuleIds();
            if ($allowedModuleIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($allowedModuleIds), '%d'));
            $where[]      = "a.act_module_id IN ({$placeholders})";
            foreach ($allowedModuleIds as $m) {
                $params[] = $m;
            }
        } else {
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

        // Page scope: filter to activities whose backing wp_post was written
        // for a specific page. Same INNER JOIN shape as the group scope above,
        // on a marker this codebase writes itself. Bound here, after gm_pm and
        // vis_in_pm, because that is the order the joins render in $sql.
        $pageJoin = '';
        if ($onlyForPageId !== null) {
            $pageJoin = ' INNER JOIN ' . $wpdb->postmeta . ' pg_pm
                             ON pg_pm.post_id    = p.ID
                            AND pg_pm.meta_key   = \'' . self::PAGE_TARGET_META . '\'
                            AND pg_pm.meta_value = %s ';
            $joinParams[] = (string) $onlyForPageId;
        }

        // Ranked-Hall channel filter (group-scoped path ONLY — Rank
        // Phase 7, §21.3 per-Hall second feed).
        //
        //   'ranked' → INNER JOIN on `_bcc_ranked_feed = '1'`: only
        //              posts explicitly stamped into the ranked channel
        //              surface. Absent meta ⇒ main-channel post ⇒
        //              excluded here.
        //   'main'   → LEFT JOIN + IS NULL anti-join: ranked-channel
        //              posts are excluded from the group's main feed so
        //              the two channels never double-serve a post.
        //   null     → no channel filter (legacy/global callers).
        //
        // Literal meta key/value (no placeholders) — constants, same
        // inline style as the `peepso_group_id` JOIN above. Guarded on
        // $onlyForGroupId so the global feed can never grow this JOIN.
        $rankedJoin = '';
        if ($onlyForGroupId !== null && $hallFeed === 'ranked') {
            $rankedJoin = ' INNER JOIN ' . $wpdb->postmeta . ' rk_pm
                               ON rk_pm.post_id    = p.ID
                              AND rk_pm.meta_key   = \'_bcc_ranked_feed\'
                              AND rk_pm.meta_value = \'1\' ';
        } elseif ($onlyForGroupId !== null && $hallFeed === 'main') {
            $rankedJoin = ' LEFT JOIN ' . $wpdb->postmeta . ' rk_pm
                              ON rk_pm.post_id  = p.ID
                             AND rk_pm.meta_key = \'_bcc_ranked_feed\' ';
            $where[] = 'rk_pm.post_id IS NULL';
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
        //
        // ⚠ ALSO skipped when $groupGateUsesRestrictedListOnly is true. That
        // is the profile Blog tab, whose rule is "can the viewer see the
        // group", not "did the author syndicate this globally". Under the
        // 'public_all' gate a members_only group blog is dropped for EVERY
        // viewer — the group's own members and the author included — so the
        // deny list below could never see those rows to judge them. With the
        // flag set, group access is decided entirely by $restrictedGroupIds.
        // Default false, so every pre-existing caller keeps this gate.
        //
        // ── Is the deny list fit to REPLACE the gate? ────────────────
        //
        // Decided here, before the gate, because dropping the gate is only
        // safe when a real list takes over. FAIL-CLOSED on two degraded
        // inputs, so the dangerous combination (gate off AND nothing
        // filtering) cannot be reached by accident:
        //
        //   1. `null` while the flag is set. null means "no list supplied",
        //      which is indistinguishable from "the caller's membership
        //      lookup degraded and it passed us nothing". The gate stays.
        //      ⚠ An EXPLICIT `[]` is deliberately NOT the same: it asserts
        //      "this viewer is restricted from nothing", which the
        //      author-scoped read (the author's own Blog tab, where every
        //      row is already their own) legitimately wants. A caller whose
        //      lookup failed must therefore pass null — or better, keep the
        //      flag off — never `[]`.
        //   2. A list longer than self::RESTRICTED_GROUP_IDS_MAX, which
        //      would be an unbounded IN list for an anonymous viewer. The
        //      gate stays, hiding strictly more than the list would.
        //
        // In both cases the result is the pre-existing 'public_all' gate:
        // some rows a member was entitled to see are withheld, and nothing
        // private is exposed.
        $restrictedGroupIdsClean = null;
        if ($restrictedGroupIds !== null) {
            $restrictedGroupIdsClean = array_values(array_unique(array_map('intval', $restrictedGroupIds)));
        }
        $denyListUsable = $restrictedGroupIdsClean !== null
            && count($restrictedGroupIdsClean) <= self::RESTRICTED_GROUP_IDS_MAX;
        $gateRelaxed    = $groupGateUsesRestrictedListOnly && $denyListUsable;

        $groupVisibilityJoin = '';
        if ($onlyForGroupId === null && !$gateRelaxed) {
            $groupVisibilityJoin = ' LEFT JOIN ' . $wpdb->postmeta . ' gx_pm
                                        ON gx_pm.post_id  = p.ID
                                       AND gx_pm.meta_key = \'peepso_group_id\'
                                     LEFT JOIN ' . $wpdb->postmeta . ' vis_pm
                                        ON vis_pm.post_id  = p.ID
                                       AND vis_pm.meta_key = \'_bcc_post_visibility\' ';
            // meta_value is varchar — compare against the literal string.
            $where[] = "(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')";
        }

        // ENFORCED viewer-restricted group exclusion (every path).
        //
        // ⚠ This is the live counterpart to the INERT `$excludedGroupIds`
        // param. That one is a documented no-op: an exclusion list passed
        // there filters nothing, which would read as a working gate in any
        // happy-path test. Callers that must actually hide group content
        // pass `$restrictedGroupIds` and get a real WHERE predicate.
        //
        // LEFT JOIN + NULL-passthrough, so a post in NO group survives:
        //   - non-group post (no meta row) → rg_pm.meta_value NULL → kept
        //   - group post, not listed       → NOT IN matches        → kept
        //   - group post, listed           → both clauses false    → dropped
        //
        // Bound as WHERE params, not JOIN params: the join itself carries no
        // placeholders, so adding it cannot shift the joinParams → params →
        // LIMIT ordering that ActivityPageScopeTest pins. A dedicated alias
        // (`rg_pm`) rather than reusing the global path's `gx_pm`, because
        // that join exists only when `$onlyForGroupId === null` while this
        // exclusion must hold on every path.
        //
        // NOT EXISTS, decided PER POST rather than per meta row. WP
        // convention is one `peepso_group_id` row per post but nothing
        // enforces it, and a join-plus-NOT-IN would be evaluated per ROW:
        // a post carrying {restricted, allowed} would have the allowed row
        // satisfy the predicate, and SELECT DISTINCT would then collapse the
        // duplicates back into one surviving item. The subquery asks the
        // question the gate actually means — "is this post in ANY restricted
        // group" — and needs no join, so nothing here can multiply rows.
        //
        //   - post in no group      → subquery matches nothing → kept
        //   - post in allowed group → no restricted match      → kept
        //   - post in a restricted group (even alongside an allowed one)
        //                           → match → NOT EXISTS false → DROPPED
        //
        // Bound as WHERE params, so this cannot shift the
        // joinParams → params → LIMIT ordering ActivityPageScopeTest pins.
        // Cast through (int) then (string): meta_value is varchar, so an id
        // arriving as a numeric string must bind identically either way.
        if ($denyListUsable && $restrictedGroupIdsClean !== []) {
            $rgPlaceholders = implode(',', array_fill(0, count($restrictedGroupIdsClean), '%s'));
            $where[]        = 'NOT EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' rg_pm'
                            . ' WHERE rg_pm.post_id = p.ID'
                            . ' AND rg_pm.meta_key = \'peepso_group_id\''
                            . ' AND rg_pm.meta_value IN (' . $rgPlaceholders . '))';
            foreach ($restrictedGroupIdsClean as $gid) {
                $params[] = (string) $gid;
            }
        }

        // Final argument order mirrors placeholder appearance in $sql:
        // JOIN params (gm_pm, then vis_in_pm, then pg_pm) → WHERE params → LIMIT.
        $allParams = array_merge($joinParams, $params, [$limit]);

        // DISTINCT: row-multiplication guard. WP convention is one
        // postmeta row per (post_id, meta_key), but nothing enforces it —
        // a duplicate `peepso_group_id` / `_bcc_post_visibility` row
        // would multiply the joined activity into identical rows,
        // duplicating feed items within a page and inflating the
        // over-fetch-by-1 has_more. Dedup cost is negligible (≤ limit+1
        // rows post-filter).
        $sql = 'SELECT DISTINCT ' . self::COLUMNS . '
                  FROM ' . self::table() . ' a
                  INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
                  ' . $groupJoin . '
                  ' . $visibilityInJoin . '
                  ' . $pageJoin . '
                  ' . $rankedJoin . '
                  ' . $groupVisibilityJoin . '
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY p.post_date_gmt DESC, a.act_id DESC
                 LIMIT %d';

        /** @phpstan-var list<ActivityRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$allParams));
        return $rows ?: [];
    }

    /**
     * Fetch a single activity row by act_id.
     *
     * @param list<int>|null $allowedModuleIds When non-null, the row is
     *   returned only if its `act_module_id` is in the allowlist — the
     *   PUBLIC permalink path ({@see \BCC\Core\Feed\ActivityFeedService::getActivityById()})
     *   passes {@see FeedItemNormalizer::publicFeedModuleIds()} so a
     *   DM / poll / group-notice / unknown-module activity can never be
     *   resolved by id and leaked through `/feed/{id}`. Enforced at the
     *   candidate query (SQL WHERE), not after retrieval. An empty list
     *   returns null. null (default) = no module filter — for internal
     *   callers (author resolution, comment-parent lookup, moderation,
     *   interaction gating) that must resolve any activity regardless of
     *   kind.
     * @phpstan-return ActivityRow|null
     */
    public static function getById(int $actId, ?array $allowedModuleIds = null): ?object
    {
        global $wpdb;

        $where  = ['a.act_id = %d'];
        $params = [$actId];
        if ($allowedModuleIds !== null) {
            if ($allowedModuleIds === []) {
                return null;
            }
            $placeholders = implode(',', array_fill(0, count($allowedModuleIds), '%d'));
            $where[]      = "a.act_module_id IN ({$placeholders})";
            foreach ($allowedModuleIds as $m) {
                $params[] = (int) $m;
            }
        }

        /** @phpstan-var ActivityRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM ' . self::table() . ' a
               INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.act_external_id
              WHERE ' . implode(' AND ', $where) . '
              LIMIT 1',
            ...$params
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
