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
     * Ceiling on `$restrictedGroupIds` and on `$viewerMemberGroupIds`,
     * for §3 bounded queries.
     *
     * The deny list is "non-public groups minus the viewer's memberships",
     * so for an ANONYMOUS viewer it is every non-public group on the site
     * and has no natural ceiling. Past this bound the list is refused and
     * the global `public_all` gate is kept instead — degrading closed.
     *
     * ⚠ This is a DEFENSIVE bound, not the binding one. The list's source,
     * `PeepSoGroupRepository::getNonOpenGroupIdsStrict()`, already refuses
     * to answer past its own 500 ceiling, so in practice a caller using
     * that seam can never reach this check. It is kept, and set to the
     * same 500, for a caller that builds a list some other way — not as
     * the thing standing between a large site and an unbounded IN list.
     */
    public const RESTRICTED_GROUP_IDS_MAX = 500;

    /**
     * Cap on `$viewerFriendIds` for the §F-02 per-post visibility gate.
     *
     * ⚠ Over the cap the list is treated as EMPTY, which DENIES every
     * level-30 post rather than admitting them. Over-strict, never leaky -
     * the same direction `$viewerMemberGroupIds` takes over its bound, and
     * for the same reason: a visibility gate that degrades toward "show
     * more" is not a gate. A viewer with more friends than this loses
     * sight of friends-only posts until the caller pages the list, which
     * is a visible, reportable shortfall rather than a silent disclosure.
     */
    public const VIEWER_FRIEND_IDS_MAX = 500;

    /**
     * `$groupGateMode` values. Named modes rather than a boolean because a
     * boolean let `[]` — which is also what a degraded lookup yields —
     * stand for "restricted from nothing" and drop the gate. A caller must
     * now say which rule it wants, and an unrecognised value relaxes
     * nothing.
     */
    public const GROUP_GATE_VIEWER_ACCESS = 'viewer_access';

    /** @see self::GROUP_GATE_VIEWER_ACCESS */
    public const GROUP_GATE_AUTHOR_SCOPED = 'author_scoped';

    /**
     * The visibilities a NON-MEMBER of a group may read, matching what the
     * group feed passes as `$groupVisibilityIn` for a non-member. Absent
     * visibility meta is not in this set, and absent means members_only.
     *
     * @var list<string>
     */
    private const NON_MEMBER_VISIBILITY = ['public_group', 'public_all'];

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
     * @param list<int>|null $restrictedGroupIds ENFORCED group exclusion — drops any activity whose backing wp_post is in one of these PeepSo groups. null = no list supplied; [] = supplied and empty ("restricted from nothing"); the two are NOT interchangeable, see `$groupGateMode`. ⚠ Deliberately NOT the same thing as `$excludedGroupIds` above, which is INERT: that one is a no-op kept for caller compatibility, so an exclusion passed there filters NOTHING. Use THIS param when a read must actually hide group content — the profile Blog tab (`/users/{handle}/blog`) passes the viewer's restricted-group list so a blog written inside a group the viewer may not see cannot surface on a public profile. Enforced as a per-post `NOT EXISTS` subquery, NOT a join: a post carrying two `peepso_group_id` rows (one restricted, one allowed) is DROPPED, where a join plus `NOT IN` would have let the allowed row satisfy the predicate and `SELECT DISTINCT` would have collapsed it back in. A post in no group always survives. Ids are de-duplicated and integer-cast before binding. Capped at {@see self::RESTRICTED_GROUP_IDS_MAX} — a longer list is REFUSED and the global gate kept, never silently truncated. bcc-core stays unaware of WHY a group is restricted — bcc-trust computes the list, same coupling-avoidance pattern as `$excludedAuthorIds`.
     * @param string|null $groupGateMode Which group-visibility rule this read wants, in place of the global-feed `public_all` gate. null (DEFAULT, every pre-existing caller) = unchanged: a group-tagged post reaches a non-group-scoped read only when it carries `_bcc_post_visibility = 'public_all'`. {@see self::GROUP_GATE_VIEWER_ACCESS} = "can the viewer see the group", for reading SOMEONE ELSE's profile Blog tab — HONOURED ONLY when `$restrictedGroupIds` is non-null and within the cap, so a caller whose lookup degraded and passed nothing keeps the stricter gate instead of leaking; it also applies the members_only parity clause, which needs `$viewerMemberGroupIds`. {@see self::GROUP_GATE_AUTHOR_SCOPED} = the author reading their OWN rows, where no deny list is required or consulted because every row the query can return is already theirs (and stays visible to them after they leave the group). ⚠ AUTHOR_SCOPED is PROVEN, not trusted: it is honoured only when `$authorIds` is exactly one id and it equals `$viewerId`, so passing the mode on a site-wide read relaxes nothing. ⚠ An unrecognised value relaxes NOTHING — this is deliberately not a boolean, because a boolean allowed `[]` to drop the gate and `[]` is also what a failed membership lookup produces. Ignored when `$onlyForGroupId !== null`, which has its own gate.
     * @param int $viewerId The viewer this read is for. Required to make {@see self::GROUP_GATE_AUTHOR_SCOPED} stick: that mode is honoured ONLY when `$authorIds` is exactly one id AND it equals this viewer, so the repository proves the read really is confined to the viewer's own rows instead of trusting a mode string. 0 (default) means "no viewer asserted", which makes AUTHOR_SCOPED a no-op. Not used by any other mode.
     * @param list<int>|null $viewerMemberGroupIds The groups the viewer BELONGS to. Only read on the {@see self::GROUP_GATE_VIEWER_ACCESS} path, where it carries the members_only parity rule: a post in one of these groups is visible at ANY visibility, while a post in a group the viewer merely can SEE (an open group they have not joined) is visible only at `public_group` / `public_all` — matching what the group feed already does for a non-member. Absent visibility meta counts as members_only and is therefore hidden from a non-member. null/[] = treat the viewer as a member of nothing, which is correct for an anonymous reader. Bounded by {@see self::RESTRICTED_GROUP_IDS_MAX}; over the bound the membership clause is dropped, which costs a member sight of their own groups' members_only posts and leaks nothing.
     * @param list<int>|null $viewerFriendIds The authors the VIEWER is a friend of, for the level-30 branch of `$enforcePostVisibility`. ⚠ bcc-core deliberately does NOT discover this itself: friendship lives in `peepso_user_friends`, a table owned by the OPTIONAL peepso-friends plugin, and joining it would make the main feed query depend on an add-on that may not be installed - a missing table turns the most important read in the product into a SQL error. bcc-trust computes the list (it already wraps `PeepSoFriendsModel` behind `PeepSoFriendGate`) and passes it, exactly the coupling-avoidance pattern `$excludedAuthorIds`, `$excludedActIds` and `$restrictedGroupIds` already use - bcc-core stays unaware of what a "friend" is and only applies the rule. null/[] = the viewer is a friend of nobody, which is correct for an anonymous reader and makes every level-30 post invisible except the viewer's own. Ids are de-duplicated and integer-cast. Capped at {@see self::VIEWER_FRIEND_IDS_MAX}, and over the cap treated as EMPTY (deny), never truncated.
     * @param bool $enforcePostVisibility OPT-IN switch for the §F-02 per-post gate; `false` (the default, and every pre-existing caller) leaves behaviour byte-identical. When true, a post is visible only at the STRICTER of its own `act_access` and its AUTHOR's `peepso_users.usr_profile_acc`, so setting a profile to "friends" or "only me" retroactively narrows the posts already written under it. The ladder: 0 and 10 = everyone (0 is what BCC's own writer stores, and PeepSo's `is_accessible_static` also treats 0 as public); 20 = any signed-in viewer; 30 = `$viewerFriendIds`; 40 = nobody. The viewer ALWAYS sees their own posts at any level, which must be in the SQL because a feed page carries many authors. ⚠ An unrecognised level DENIES - the `CASE` has no catch-all that admits. ⚠ ADMINS are NOT exempted here: an admin read is expressed by the caller passing `false`, because encoding a capability check in a repository would put policy in the wrong layer. ⚠ A missing `peepso_users` row counts as 10 (public), matching that column's schema default and what bcc-trust's own reader returns when PeepSo is absent - not a deliberate fail-open, but say so when auditing.
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
        ?string $groupGateMode = null,
        ?array $viewerMemberGroupIds = null,
        int $viewerId = 0,
        ?array $viewerFriendIds = null,
        bool $enforcePostVisibility = false
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
        // ⚠ ALSO skipped when $groupGateMode relaxes it. That is the profile
        // Blog tab, whose rule is "can the viewer see the group", not "did
        // the author syndicate this globally". Under the 'public_all' gate a
        // members_only group blog is dropped for EVERY viewer — the group's
        // own members and the author included — so the deny list below could
        // never see those rows to judge them. Mode null (the default, every
        // pre-existing caller) keeps this gate.
        //
        // ── May the gate be relaxed? ─────────────────────────────────
        //
        // Decided here, BEFORE the gate, because dropping the gate is only
        // safe once something real takes over. The mode is an explicit
        // string rather than a boolean for one reason: a boolean made
        // `[]` — "restricted from nothing" — enough to drop the gate, and
        // `[]` is also what a degraded lookup produces. The caller must now
        // NAME which rule it wants, and an unrecognised value relaxes
        // nothing.
        //
        //   GROUP_GATE_VIEWER_ACCESS — honoured ONLY with a usable deny
        //     list (non-null, within the cap). null means "no list
        //     supplied", indistinguishable from "my lookup failed", so the
        //     gate stays. This is the mode for reading SOMEONE ELSE's
        //     profile.
        //   GROUP_GATE_AUTHOR_SCOPED — the author reading their own rows.
        //     No deny list is required or consulted, because every row the
        //     query can return is already the viewer's own. This is the
        //     ONLY way to relax the gate without a list, and it has to be
        //     asked for by name.
        //
        // Degrading always lands on the pre-existing 'public_all' gate.
        // That withholds rows a member was entitled to see; it does not
        // expose anything that was private before. (It is not strictly
        // MORE restrictive in every respect — a public_all post inside a
        // restricted group is still admitted, exactly as it is on the main
        // feed today — but nothing new becomes visible.)
        $restrictedGroupIdsClean = null;
        if ($restrictedGroupIds !== null) {
            $restrictedGroupIdsClean = array_values(array_unique(array_map('intval', $restrictedGroupIds)));
        }
        $denyListUsable = $restrictedGroupIdsClean !== null
            && count($restrictedGroupIdsClean) <= self::RESTRICTED_GROUP_IDS_MAX;

        // AUTHOR_SCOPED must PROVE it is author-scoped.
        //
        // The mode is just a string, so on its own it would let any caller
        // drop the gate on a site-wide read with no deny list and no parity
        // clause — the widest possible hole, opened by one argument. The
        // claim is only honoured when the query really is confined to the
        // viewer's own rows: exactly one author id, and that id is the
        // viewer. A mismatched, absent or multi-author read relaxes
        // nothing, so the repository verifies the premise instead of
        // trusting the label.
        $authorScopedProven = $groupGateMode === self::GROUP_GATE_AUTHOR_SCOPED
            && $viewerId > 0
            && is_array($authorIds)
            && count($authorIds) === 1
            && (int) reset($authorIds) === $viewerId;

        $gateRelaxed = match (true) {
            $authorScopedProven                              => true,
            $groupGateMode === self::GROUP_GATE_VIEWER_ACCESS => $denyListUsable,
            // null, an unproven author scope, or anything unrecognised
            // relaxes nothing.
            default                                          => false,
        };

        // ⚠ Group-scoped path with a SUPPLIED-but-unusable deny list: there
        // is no gate standing behind it here (the global 'public_all' gate
        // never applies when $onlyForGroupId is set), so skipping the
        // predicate would serve the group's rows unfiltered. A caller that
        // bothered to pass a list is asserting the read needs one, so
        // refuse the read instead of quietly widening it.
        if ($onlyForGroupId !== null && $restrictedGroupIds !== null && !$denyListUsable) {
            return [];
        }

        $authorAccJoin = '';

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

        // members_only PARITY, on the viewer-access path only.
        //
        // The deny list removes groups the viewer may not SEE. It says
        // nothing about a group they can see but do not BELONG to — an
        // OPEN group — and `members_only` has to mean members_only there
        // too. The group feed already works this way: for a non-member it
        // passes $groupVisibilityIn = ['public_group','public_all'], so a
        // members_only post never reaches a non-member. Without the same
        // rule here, relaxing the gate on a profile would publish every
        // members_only post from every open group the author writes in.
        //
        // One clause, three ways for a post to qualify, each a per-post
        // subquery so a duplicate meta row cannot multiply or rescue a row:
        //
        //   1. the post is in NO group                        → always fine
        //   2. the post's group is one the VIEWER BELONGS TO  → member sees
        //      every visibility, members_only included
        //   3. the post is public_group or public_all         → the two
        //      values that are not member-private
        //
        // Absent visibility meta deliberately fails (3): absent means
        // members_only, which is the same INNER-JOIN-not-LEFT-JOIN
        // invariant the group feed relies on.
        //
        // NOT applied for GROUP_GATE_AUTHOR_SCOPED — the author's own rows
        // are theirs to see whatever the visibility, and after they leave
        // the group.
        // Global path only, matching the docblock. On the group-scoped path
        // the caller already passes `$groupVisibilityIn` for a non-member,
        // so this clause would only duplicate it — and a predicate that
        // merely narrows is still a predicate the docblock did not promise.
        if ($gateRelaxed
            && $groupGateMode === self::GROUP_GATE_VIEWER_ACCESS
            && $onlyForGroupId === null
        ) {
            $memberGroupsClean = [];
            if ($viewerMemberGroupIds !== null) {
                $memberGroupsClean = array_values(array_unique(array_map('intval', $viewerMemberGroupIds)));
                // Bounded like the deny list. Over the cap the membership
                // clause is DROPPED, which costs a member sight of their
                // own groups' members_only posts — restrictive, not leaky.
                if (count($memberGroupsClean) > self::RESTRICTED_GROUP_IDS_MAX) {
                    $memberGroupsClean = [];
                }
            }

            $alternatives = [
                'NOT EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' mg_abs'
                    . ' WHERE mg_abs.post_id = p.ID'
                    . ' AND mg_abs.meta_key = \'peepso_group_id\')',
            ];

            if ($memberGroupsClean !== []) {
                $mgPlaceholders = implode(',', array_fill(0, count($memberGroupsClean), '%s'));
                $alternatives[] = 'EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' mg_pm'
                    . ' WHERE mg_pm.post_id = p.ID'
                    . ' AND mg_pm.meta_key = \'peepso_group_id\''
                    . ' AND mg_pm.meta_value IN (' . $mgPlaceholders . '))';
            }

            // PER-POST, not "any row qualifies". A post with duplicate
            // visibility rows {members_only, public_group} would satisfy a
            // bare EXISTS on the public values and be shown — the same
            // per-row mistake the deny list had. So the post must have a
            // public row AND no non-public row. WP writes one row per key,
            // but nothing enforces it, and this is a visibility gate.
            $visPlaceholdersNm = implode(',', array_fill(0, count(self::NON_MEMBER_VISIBILITY), '%s'));
            $alternatives[]    = '(EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' nmv_pm'
                . ' WHERE nmv_pm.post_id = p.ID'
                . ' AND nmv_pm.meta_key = \'_bcc_post_visibility\''
                . ' AND nmv_pm.meta_value IN (' . $visPlaceholdersNm . '))'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . ' nmv_neg'
                . ' WHERE nmv_neg.post_id = p.ID'
                . ' AND nmv_neg.meta_key = \'_bcc_post_visibility\''
                . ' AND nmv_neg.meta_value NOT IN (' . $visPlaceholdersNm . ')))';

            $where[] = '(' . implode(' OR ', $alternatives) . ')';

            // Params in clause order: member ids, then the visibility values
            // twice — once for the positive EXISTS, once for the negative.
            foreach ($memberGroupsClean as $mgid) {
                $params[] = (string) $mgid;
            }
            foreach (self::NON_MEMBER_VISIBILITY as $vis) {
                $params[] = $vis;
            }
            foreach (self::NON_MEMBER_VISIBILITY as $vis) {
                $params[] = $vis;
            }
        }

        // -- §F-02 per-post effective visibility (OPT-IN) ---------------
        //
        // A post is visible at the STRICTER of its own `act_access` and
        // its AUTHOR's `usr_profile_acc`. That is what makes a profile
        // setting retroactive: turning a profile to "friends" narrows the
        // posts already written under it, instead of leaving them public
        // while the profile page itself is gated.
        //
        // ⚠ OFF BY DEFAULT. Every pre-existing caller passes nothing and
        // gets byte-identical SQL - no join, no clause, no params. The
        // golden full-statement snapshots in the sibling test file are what
        // hold that claim.
        //
        // ⚠ THE FRIENDS TABLE IS NOT JOINED, DELIBERATELY. Level 30 is
        // friendship, which lives in `peepso_user_friends` - a table owned
        // by the OPTIONAL peepso-friends plugin. Joining it would make the
        // main feed query fail outright wherever that add-on is absent: a
        // privacy rule that can take down the product's most important
        // read. bcc-trust passes the ids instead, the same
        // coupling-avoidance pattern as `$excludedAuthorIds` and
        // `$restrictedGroupIds`.
        //
        // ⚠ `peepso_users` IS joined, and that is a different question:
        // it belongs to PeepSo CORE, the same plugin that owns
        // `peepso_activities`, which this whole query already requires. The
        // join adds no dependency that is not already load-bearing here.
        // LEFT, not INNER, so an author with no PeepSo row still appears -
        // their level then COALESCEs to 10, the column's schema default.
        if ($enforcePostVisibility) {
            $friendsClean = [];
            if (is_array($viewerFriendIds)) {
                foreach ($viewerFriendIds as $fid) {
                    $f = (int) $fid;
                    if ($f > 0) {
                        $friendsClean[$f] = true;
                    }
                }
            }
            $friendsClean = array_keys($friendsClean);

            // Over the cap: EMPTY, which denies level 30 rather than
            // admitting it. Truncating would admit the first N friends and
            // deny the rest with no signal - a gate whose behaviour
            // depends on array order.
            if (count($friendsClean) > self::VIEWER_FRIEND_IDS_MAX) {
                $friendsClean = [];
            }

            $authorAccJoin = ' LEFT JOIN ' . $wpdb->prefix . 'peepso_users pu'
                . ' ON pu.usr_id = p.post_author';

            // No friends (or anonymous) => the level-30 branch is a literal
            // 0. An empty `IN ()` is a SQL syntax error, and spelling the
            // branch out keeps the CASE total rather than relying on the
            // catch-all.
            $friendBranch = '0';
            $friendParams = [];
            if ($viewerId > 0 && $friendsClean !== []) {
                $friendBranch = 'IF(p.post_author IN ('
                    . implode(',', array_fill(0, count($friendsClean), '%d'))
                    . '), 1, 0)';
                $friendParams = $friendsClean;
            }

            // GREATEST picks the stricter level because the ladder is
            // ordered: 10 < 20 < 30 < 40. `act_access <= 0` normalises to
            // 10 - BCC's own writer stores 0, and PeepSo's
            // `is_accessible_static` also treats 0 as public.
            //
            // ⚠ The CASE has NO admitting catch-all: an unrecognised
            // effective level (a future PeepSo value, a corrupt row) falls
            // to `ELSE 0` and is hidden. That is the opposite of the
            // `is_numeric` defect found in the profile gate, where an
            // unreadable value cast to 0 and 0 meant PUBLIC.
            $where[] = '('
                . 'CASE WHEN %d > 0 AND p.post_author = %d THEN 1'
                . ' ELSE CASE GREATEST('
                .     'IF(a.act_access IS NULL OR a.act_access <= 0, 10, a.act_access),'
                .     ' COALESCE(pu.usr_profile_acc, 10)'
                . ')'
                .   ' WHEN 10 THEN 1'
                .   ' WHEN 20 THEN IF(%d > 0, 1, 0)'
                .   ' WHEN 30 THEN ' . $friendBranch
                .   ' ELSE 0'
                . ' END END) = 1';

            // Params in clause order: the own-post guard reads $viewerId
            // twice, then the members-only branch once, then the friend
            // ids. ⚠ This list and the placeholders above are matched
            // POSITIONALLY and a mismatch is silent - it would bind a
            // viewer id where a friend id belongs and quietly change who
            // can see what. The sibling test asserts the RENDERED values.
            $params[] = $viewerId;
            $params[] = $viewerId;
            $params[] = $viewerId;
            foreach ($friendParams as $fid) {
                $params[] = $fid;
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
                  ' . $authorAccJoin . '
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
