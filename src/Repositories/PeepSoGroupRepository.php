<?php

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to PeepSo Groups (the data layer behind BCC Locals
 * per §E3 of the V1 plan).
 *
 * Schema reference (verify on PeepSo schema bumps):
 *   - PeepSo Groups are a CPT: wp_posts WHERE post_type = 'peepso-group'
 *   - Membership: {prefix}peepso_group_members (gm_id, gm_user_id,
 *     gm_group_id, gm_user_status, gm_joined, gm_invited_by_id,
 *     gm_accepted_by_id)
 *   - Active members are gm_user_status LIKE 'member%' (excludes
 *     pending_*, banned, block_invites — see install/activate.php)
 *
 * BCC Locals filter (V1 stub): post_title LIKE 'Local %'. The §E3
 * convention is "Local NNN <chain> Base Fan" — name pattern is the
 * canonical filter for V1. A dedicated group-meta marker (e.g.
 * `bcc_is_local`) is deferred to post-V1; switching the filter is a
 * one-line change in the WHERE clause here.
 *
 * No SELECT *. All queries bounded by LIMIT or aggregate. No writes —
 * PeepSo owns the write path.
 */
final class PeepSoGroupRepository
{
    private const TABLE_MEMBERS_SUFFIX = 'peepso_group_members';
    private const POST_TYPE            = 'peepso-group';
    private const POST_STATUS          = 'publish';
    private const LOCAL_TITLE_PATTERN  = 'Local %'; // §E3 naming convention
    private const ACTIVE_MEMBER_STATUS = 'member%'; // matches member, member_moderator, member_manager, member_owner, member_readonly

    // Non-open privacy values per PeepSo's `peepso_group_privacy` post-meta:
    //   0 = public (open), 1 = closed, 2 = secret. NFT-gated groups carry
    //   privacy = 1 (closed) plus a sidecar marker — they are a strict
    //   subset of "non-open." See ValueObjects/PeepSoPrivacy.php.
    private const NON_OPEN_PRIVACY_VALUES = ['1', '2'];

    // Cache for getNonOpenGroupIds — generation-counter pattern per §5.
    // Read paths key on `:gen`, write paths bump the generation. Hooks
    // wired in bcc-core.php fire on add/update/delete of the
    // `peepso_group_privacy` post-meta.
    private const NONOPEN_CACHE_GROUP    = 'bcc_core:groups';
    private const NONOPEN_CACHE_KEY_LIST = 'non_open_group_ids';
    private const NONOPEN_CACHE_KEY_GEN  = 'non_open_gen';
    private const NONOPEN_CACHE_TTL      = 600; // 10 min
    private const NONOPEN_DEFAULT_LIMIT  = 500; // V1 scale: ~hundreds of groups

    private static function membersTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_MEMBERS_SUFFIX;
    }

    /**
     * Locals matching the §E3 naming pattern, with member counts.
     *
     * @return list<object{
     *   id: numeric-string,
     *   post_name: string,
     *   post_title: string,
     *   member_count: numeric-string
     * }>
     */
    public static function listLocals(?string $chain, int $offset, int $limit): array
    {
        global $wpdb;
        $members = self::membersTable();

        $select = "SELECT p.ID AS id, p.post_name, p.post_title,
                          COALESCE(COUNT(gm.gm_id), 0) AS member_count
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$members} gm ON gm.gm_group_id = p.ID
                                            AND gm.gm_user_status LIKE %s
                    WHERE p.post_type = %s
                      AND p.post_status = %s
                      AND p.post_title LIKE %s";

        $orderLimit = " GROUP BY p.ID
                        ORDER BY p.post_title ASC
                        LIMIT %d OFFSET %d";

        if ($chain === null) {
            $sql = $wpdb->prepare(
                $select . $orderLimit,
                self::ACTIVE_MEMBER_STATUS,
                self::POST_TYPE,
                self::POST_STATUS,
                self::LOCAL_TITLE_PATTERN,
                $limit,
                $offset
            );
        } else {
            $titleHasChain = '%' . $wpdb->esc_like($chain) . '%';
            $sql = $wpdb->prepare(
                $select . " AND p.post_title LIKE %s" . $orderLimit,
                self::ACTIVE_MEMBER_STATUS,
                self::POST_TYPE,
                self::POST_STATUS,
                self::LOCAL_TITLE_PATTERN,
                $titleHasChain,
                $limit,
                $offset
            );
        }

        /** @var list<object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($sql);
        return $rows ?: [];
    }

    /**
     * Find a single Local by its post_name (slug). Same row shape as
     * `listLocals` — id + slug + title + member_count — so the service
     * layer can reuse the same render path for the detail page that it
     * uses for the directory.
     *
     * Returns null when no row matches the slug + Local naming filter.
     * The slug uniqueness is implicit (post_name is per-type-unique
     * in WP) but we still LIMIT 1 defensively.
     *
     * @return object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null
     */
    public static function findOneBySlug(string $slug): ?object
    {
        if ($slug === '') {
            return null;
        }

        global $wpdb;
        $members = self::membersTable();

        $sql = $wpdb->prepare(
            "SELECT p.ID AS id, p.post_name, p.post_title,
                    COALESCE(COUNT(gm.gm_id), 0) AS member_count
               FROM {$wpdb->posts} p
               LEFT JOIN {$members} gm ON gm.gm_group_id = p.ID
                                      AND gm.gm_user_status LIKE %s
              WHERE p.post_type = %s
                AND p.post_status = %s
                AND p.post_name = %s
                AND p.post_title LIKE %s
              GROUP BY p.ID
              LIMIT 1",
            self::ACTIVE_MEMBER_STATUS,
            self::POST_TYPE,
            self::POST_STATUS,
            $slug,
            self::LOCAL_TITLE_PATTERN
        );

        /** @var object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null $row */
        $row = $wpdb->get_row($sql);
        return $row;
    }

    /**
     * Find a single Local by its group id. Same row shape + Local-pattern
     * filter as `findOneBySlug` — used by the join/leave/set-primary write
     * paths to verify the target group is actually a BCC Local before
     * mutating membership (rather than e.g. a PeepSo support group that
     * happens to share the id space).
     *
     * @return object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null
     */
    public static function findOneById(int $groupId): ?object
    {
        if ($groupId <= 0) {
            return null;
        }

        global $wpdb;
        $members = self::membersTable();

        $sql = $wpdb->prepare(
            "SELECT p.ID AS id, p.post_name, p.post_title,
                    COALESCE(COUNT(gm.gm_id), 0) AS member_count
               FROM {$wpdb->posts} p
               LEFT JOIN {$members} gm ON gm.gm_group_id = p.ID
                                      AND gm.gm_user_status LIKE %s
              WHERE p.post_type = %s
                AND p.post_status = %s
                AND p.ID = %d
                AND p.post_title LIKE %s
              GROUP BY p.ID
              LIMIT 1",
            self::ACTIVE_MEMBER_STATUS,
            self::POST_TYPE,
            self::POST_STATUS,
            $groupId,
            self::LOCAL_TITLE_PATTERN
        );

        /** @var object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null $row */
        $row = $wpdb->get_row($sql);
        return $row;
    }

    /**
     * Bulk-fetch group post info (id, slug, title, content, member_count)
     * for a set of group_ids. Used by the User view-model's `locals`
     * field and by the cross-kind discovery endpoint to enrich each
     * group with display info — single query, no N+1.
     *
     * `post_content` is the group description as PeepSo stores it.
     * Callers are responsible for stripping tags + truncating before
     * surfacing to the wire.
     *
     * @param int[] $groupIds
     * @return array<int, object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}>
     */
    public static function findManyByIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        global $wpdb;
        $members      = self::membersTable();
        $placeholders = implode(',', array_fill(0, count($groupIds), '%d'));

        $args = array_merge([self::ACTIVE_MEMBER_STATUS, self::POST_TYPE], $groupIds);
        $sql  = $wpdb->prepare(
            "SELECT p.ID AS id, p.post_name, p.post_title, p.post_content,
                    COALESCE(COUNT(gm.gm_id), 0) AS member_count
               FROM {$wpdb->posts} p
               LEFT JOIN {$members} gm ON gm.gm_group_id = p.ID
                                      AND gm.gm_user_status LIKE %s
              WHERE p.post_type = %s
                AND p.ID IN ({$placeholders})
              GROUP BY p.ID
              LIMIT 200",
            ...$args
        );

        /** @var list<object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->id] = $row;
        }
        return $map;
    }

    /**
     * Cross-kind single-group lookup by slug (post_name). Same row shape
     * as `findManyByIds` (id + slug + title + post_content + member_count).
     *
     * Unlike `findOneBySlug` (Locals-only — title-pattern filter applied),
     * this method returns ANY published peepso-group regardless of kind
     * — used by the cross-kind /bcc/v1/groups/{slug} detail endpoint to
     * resolve nft / local / system / user groups uniformly.
     *
     * Privacy is NOT filtered here — secret groups DO match. Defense-in-
     * depth visibility (404 secret-from-non-members) lives in the
     * caller (GroupsService) so the repository stays a single-purpose
     * read seam.
     *
     * Returns null when no row matches the slug. Uniqueness is implicit
     * (post_name is per-type-unique in WP) but we LIMIT 1 defensively.
     *
     * @return object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}|null
     */
    public static function findGroupBySlug(string $slug): ?object
    {
        if ($slug === '') {
            return null;
        }

        global $wpdb;
        $members = self::membersTable();

        $sql = $wpdb->prepare(
            "SELECT p.ID AS id, p.post_name, p.post_title, p.post_content,
                    COALESCE(COUNT(gm.gm_id), 0) AS member_count
               FROM {$wpdb->posts} p
               LEFT JOIN {$members} gm ON gm.gm_group_id = p.ID
                                      AND gm.gm_user_status LIKE %s
              WHERE p.post_type = %s
                AND p.post_status = %s
                AND p.post_name = %s
              GROUP BY p.ID
              LIMIT 1",
            self::ACTIVE_MEMBER_STATUS,
            self::POST_TYPE,
            self::POST_STATUS,
            $slug
        );

        /** @var object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}|null $row */
        $row = $wpdb->get_row($sql);
        return $row;
    }

    /**
     * Batched primary-Local lookup. For a set of user_ids, returns the
     * group post info (same shape as `findManyByIds`) of each user's
     * primary Local — the group pointed to by their
     * `bcc_primary_local_group_id` user_meta. Users without a primary
     * (or whose pointer no longer resolves to an active Local) are
     * absent from the map.
     *
     * Used by list-shape consumers (e.g. /members directory) where
     * 24 rows × `get_user_meta` + 24 × `findOneById` would N+1.
     * Replaces ~48 sequential calls with two SQLs.
     *
     * Note on scope: this returns only the *primary* Local — the row
     * the user has elected as their main Local. Callers that need the
     * full membership list should use `findUserMemberships` per-user
     * (or build a batched sibling if a list surface needs it). The
     * /members card only shows the primary, so that's all we batch.
     *
     * Empty `$userIds` short-circuits — no SQL.
     *
     * @param int[] $userIds Bounded by caller (directory `per_page`
     *                       cap, e.g. 50). The user_meta + posts IN
     *                       clauses scale linearly.
     * @return array<int, object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}>
     *         user_id → primary-Local group post info
     */
    public static function getPrimaryLocalForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // Sanitize + dedupe.
        $cleanIds = [];
        foreach ($userIds as $id) {
            $intVal = (int) $id;
            if ($intVal > 0) {
                $cleanIds[$intVal] = true;
            }
        }
        if ($cleanIds === []) {
            return [];
        }
        $idList = array_keys($cleanIds);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        // Step 1: pull the bcc_primary_local_group_id meta for every
        // user in one SQL. Skips users with no row (they don't have a
        // primary set).
        /** @var list<array{user_id: string, meta_value: string}> $metaRows */
        $metaRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_value
                   FROM {$wpdb->usermeta}
                  WHERE meta_key = 'bcc_primary_local_group_id'
                    AND user_id IN ({$placeholders})",
                ...$idList
            ),
            ARRAY_A
        );

        $userToGroup = [];
        $groupIds    = [];
        foreach (($metaRows ?: []) as $row) {
            $userId  = (int) $row['user_id'];
            $groupId = is_numeric($row['meta_value']) ? (int) $row['meta_value'] : 0;
            if ($userId > 0 && $groupId > 0) {
                $userToGroup[$userId]    = $groupId;
                $groupIds[$groupId]      = true;
            }
        }
        if ($userToGroup === []) {
            return [];
        }

        // Step 2: bulk-resolve the group post info via the existing
        // helper. Groups that no longer exist (deleted Locals) drop
        // out — `findManyByIds` filters by post_type + ID match.
        $groupInfo = self::findManyByIds(array_keys($groupIds));

        $out = [];
        foreach ($userToGroup as $userId => $groupId) {
            if (isset($groupInfo[$groupId])) {
                $out[$userId] = $groupInfo[$groupId];
            }
        }
        return $out;
    }

    /**
     * Inverse of getPrimaryLocalForUsers. Given a Local group_id, return
     * every user who's designated it as primary. Used by the
     * "post in your primary Local" notification dispatcher (bcc-trust)
     * to fan out to the recipient set.
     *
     * The meta key string is hardcoded here rather than imported from
     * bcc-trust's LocalsService::META_PRIMARY_GROUP because bcc-core
     * cannot depend on bcc-trust. The two declarations are intentionally
     * paired: changing one without the other is a §VIII pattern
     * violation — see getPrimaryLocalForUsers above for the same
     * coupling note.
     *
     * Bounded by $limit per §5 (no unbounded SELECT). The default 1000
     * is well above today's typical primary-Local membership; revisit
     * with cursor pagination when a real Local crosses ~500 primary
     * members.
     *
     * Order: server-defined (by user_id ascending). Deduped via the
     * meta-key uniqueness contract — `bcc_primary_local_group_id` is
     * a singleton per user (one row max, written via update_user_meta
     * in LocalsService::setPrimaryLocal).
     *
     * @return list<int> user IDs (positive integers), bounded by $limit
     */
    public static function findUsersByPrimaryLocal(int $groupId, int $limit = 1000): array
    {
        if ($groupId <= 0 || $limit <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<string>|null $rows */
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id
                   FROM {$wpdb->usermeta}
                  WHERE meta_key = %s
                    AND meta_value = %s
                  ORDER BY user_id ASC
                  LIMIT %d",
                'bcc_primary_local_group_id',
                (string) $groupId,
                $limit
            )
        );

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $value) {
            $userId = (int) $value;
            if ($userId > 0) {
                $out[] = $userId;
            }
        }
        return $out;
    }

    /**
     * Bulk-fetch the viewer's active memberships across a set of
     * group_ids. Used by LocalsService to enrich the catalog with
     * per-row viewer_membership without an N+1.
     *
     * Inactive PeepSo statuses (pending_*, banned, block_invites) are
     * excluded — only rows where gm_user_status starts with 'member'
     * surface here.
     *
     * @param int[] $groupIds
     * @return array<int, object{group_id: numeric-string, joined_at: string}>
     */
    public static function findUserMemberships(int $userId, array $groupIds): array
    {
        if ($userId <= 0 || $groupIds === []) {
            return [];
        }

        global $wpdb;
        $members = self::membersTable();
        $placeholders = implode(',', array_fill(0, count($groupIds), '%d'));

        $args = array_merge([$userId], $groupIds, [self::ACTIVE_MEMBER_STATUS]);
        $sql = $wpdb->prepare(
            "SELECT gm_group_id AS group_id, gm_joined AS joined_at
               FROM {$members}
              WHERE gm_user_id = %d
                AND gm_group_id IN ({$placeholders})
                AND gm_user_status LIKE %s
              LIMIT 200",
            ...$args
        );

        /** @var list<object{group_id: numeric-string, joined_at: string}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->group_id] = $row;
        }
        return $map;
    }

    /**
     * Membership status row for a single (user, group). Returns the
     * raw `gm_user_status` enum value (`member`, `member_owner`,
     * `member_manager`, `member_moderator`, `member_readonly`,
     * `pending_user`, `pending_admin`, `banned`, `block_invites`) or
     * null if the user has no row in this group.
     *
     * Used by the leave path to refuse owner removal — PeepSo's
     * member_leave is unconditional on status, so the caller must
     * gate the call.
     */
    public static function getMembershipStatus(int $userId, int $groupId): ?string
    {
        if ($userId <= 0 || $groupId <= 0) {
            return null;
        }

        global $wpdb;
        $members = self::membersTable();

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT gm_user_status
               FROM {$members}
              WHERE gm_user_id  = %d
                AND gm_group_id = %d
              LIMIT 1",
            $userId,
            $groupId
        ));

        return $row !== null ? (string) $row : null;
    }

    /**
     * IDs of all browsable peepso-groups — published, post_type =
     * 'peepso-group', not `is_secret`. Drives the discovery endpoint.
     *
     * Closed groups ARE included: per the plan, a non-member sees that
     * the group exists (encourages buying in for NFT-gated cases) but
     * PeepSo's privacy keeps content private. Secret groups stay hidden
     * from anyone who isn't already a member.
     *
     * @return list<int>
     */
    public static function listBrowsableGroupIds(int $limit = 500): array
    {
        global $wpdb;

        // peepso_group_privacy = 2 is PRIVACY_SECRET; exclude.
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->postmeta} pm
                    ON pm.post_id  = p.ID
                   AND pm.meta_key = %s
              WHERE p.post_type   = %s
                AND p.post_status = %s
                AND (pm.meta_value IS NULL OR CAST(pm.meta_value AS UNSIGNED) <> 2)
              ORDER BY p.ID DESC
              LIMIT %d",
            'peepso_group_privacy',
            self::POST_TYPE,
            self::POST_STATUS,
            $limit
        ));

        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * All groups the user is an active member of, IDs only. Used by
     * the profile Groups tab to populate the cross-kind list before
     * each group is hydrated via GroupContextResolver.
     *
     * "Active" excludes pending_*, banned, block_invites — same
     * `member%` filter the rest of this repository uses.
     *
     * INTENTIONALLY UNCACHED — do not add a wp_cache/transient layer.
     * This feeds FeedRankingService::resolveRestrictedGroupIds(), which
     * SUBTRACTS the viewer's memberships from the hidden-group set
     * (closed/secret/NFT-gated). A stale "still a member" entry would
     * therefore *unhide* gated posts from a user who was removed, banned,
     * or downgraded — a §4.7.x content leak, not just a stale read.
     * PeepSo owns several membership write paths (member_modify role
     * changes, ban, group-delete cascade) that fire no hook BCC can
     * reliably bust on, so a generation counter we'd never bump would
     * surface stale memberships after those writes. This follows the
     * same uncached-read convention documented on {@see listGroupMembers}.
     * The query is a single-user range scan on `INDEX gm_user_id` — cheap.
     * If this ever needs optimizing, build an authoritatively-invalidated
     * membership read-model, not a cache-layer shortcut.
     *
     * @return list<int>
     */
    public static function getUserMemberGroupIds(int $userId, int $limit = 200): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;
        $members = self::membersTable();

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT gm_group_id
               FROM {$members}
              WHERE gm_user_id     = %d
                AND gm_user_status LIKE %s
              ORDER BY gm_joined DESC
              LIMIT %d",
            $userId,
            self::ACTIVE_MEMBER_STATUS,
            $limit
        ));

        return array_values(array_map('intval', $ids ?: []));
    }

    /**
     * Co-members of a set of groups: for every active member of any
     * group in `$groupIds`, return how many of those groups they share
     * and one representative shared group_id. Drives the who-to-follow
     * recommender's "in a Local / holder community with you" affinity
     * signal — the caller passes the VIEWER's own group_ids and gets
     * back the people who overlap, with the overlap count as the
     * signal strength.
     *
     * The viewer is excluded (`gm_user_id != $excludeUserId`) so they
     * never recommend themselves. Active-membership filter only
     * (`gm_user_status LIKE 'member%'`) — same convention as the rest of
     * this repository.
     *
     * `shared_group_id` is the group with the lowest id among the shared
     * set (deterministic, MIN()) — the caller resolves it to a display
     * name and classifies it (Local vs holder community) via the group
     * title/marker. We return ONE representative rather than the full
     * list because the recommender's reason line names a single group;
     * the `shared_count` carries the strength.
     *
     * Bounded (§4): `$groupIds` is the viewer's membership (already
     * capped at ~200 by getUserMemberGroupIds), and the grouped
     * candidate set by `$limit`. ORDER BY is by shared_count DESC then
     * user_id — this is a SELECTION bound (keep the strongest overlaps
     * within the cap), NOT a popularity ranking of the output; the
     * service layer re-scores with its own affinity weights and never
     * trusts this order for the final result.
     *
     * @param int[] $groupIds  The viewer's group memberships.
     * @return array<int, array{shared_count: int, shared_group_id: int}>
     *         candidate user_id => overlap info
     */
    public static function getCoMembersOfGroups(
        array $groupIds,
        int $excludeUserId,
        int $limit = 300
    ): array {
        if ($groupIds === [] || $limit <= 0) {
            return [];
        }
        if ($limit > 500) {
            $limit = 500;
        }

        // Sanitize + dedupe the group id list.
        $clean = [];
        foreach ($groupIds as $gid) {
            $intVal = (int) $gid;
            if ($intVal > 0) {
                $clean[$intVal] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);

        global $wpdb;
        $members      = self::membersTable();
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        $params = $idList;
        $params[] = self::ACTIVE_MEMBER_STATUS;
        $params[] = $excludeUserId;
        $params[] = $limit;

        $sql = $wpdb->prepare(
            "SELECT gm_user_id            AS user_id,
                    COUNT(DISTINCT gm_group_id) AS shared_count,
                    MIN(gm_group_id)      AS shared_group_id
               FROM {$members}
              WHERE gm_group_id IN ({$placeholders})
                AND gm_user_status LIKE %s
                AND gm_user_id <> %d
              GROUP BY gm_user_id
              ORDER BY shared_count DESC, gm_user_id ASC
              LIMIT %d",
            ...$params
        );

        /** @var list<array{user_id: string, shared_count: string, shared_group_id: string}>|null $rows */
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $out = [];
        foreach (($rows ?: []) as $row) {
            $userId = (int) $row['user_id'];
            if ($userId > 0) {
                $out[$userId] = [
                    'shared_count'    => (int) $row['shared_count'],
                    'shared_group_id' => (int) $row['shared_group_id'],
                ];
            }
        }
        return $out;
    }

    /**
     * IDs of all published peepso-groups whose privacy is NOT open —
     * `peepso_group_privacy` post-meta IN ('1', '2'), i.e. closed OR
     * secret (NFT-gated groups are a closed-privacy subset).
     *
     * Bounded list (V1 scale: ~hundreds of groups). Used as the
     * candidate pool the bcc-trust feed layer subtracts the viewer's
     * memberships from to build the §4.7.x main-feed exclusion list,
     * mirroring the {@see PeepSoActivityRepository::getActivities()
     * `$excludedAuthorIds`} pattern (caller decides WHY to drop, repo
     * stays a single-purpose seam).
     *
     * Cached via the §5 generation-counter pattern (key
     * `non_open_group_ids:<gen>`). Generation is bumped by the
     * `updated_post_meta` / `added_post_meta` / `deleted_post_meta`
     * hooks in bcc-core.php whenever `peepso_group_privacy` is written.
     * 10-minute TTL bounds the worst-case staleness window if a write
     * arrives via a code path that bypasses the action hooks (e.g.
     * direct SQL).
     *
     * Defensive posture: when no groups match (fresh install, or every
     * group is open), the cached value is `[]` and feed callers
     * short-circuit the exclusion path entirely — no JOIN cost.
     *
     * @return list<int>
     */
    public static function getNonOpenGroupIds(int $limit = self::NONOPEN_DEFAULT_LIMIT): array
    {
        if ($limit <= 0) {
            return [];
        }
        if ($limit > self::NONOPEN_DEFAULT_LIMIT) {
            $limit = self::NONOPEN_DEFAULT_LIMIT;
        }

        $genKey = self::nonOpenCacheKey($limit);
        $cached = wp_cache_get($genKey, self::NONOPEN_CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<int> $cached */
            return $cached;
        }

        global $wpdb;

        // INNER JOIN postmeta on peepso_group_privacy IN ('1','2'). The
        // postmeta (post_id, meta_key) index is one of WP's tightest;
        // restricting the post side to peepso-group + publish keeps the
        // candidate set tiny on a real install. Explicit column list.
        $placeholders = implode(',', array_fill(0, count(self::NON_OPEN_PRIVACY_VALUES), '%s'));

        $params = [
            'peepso_group_privacy',
            self::POST_TYPE,
            self::POST_STATUS,
        ];
        foreach (self::NON_OPEN_PRIVACY_VALUES as $val) {
            $params[] = $val;
        }
        $params[] = $limit;

        $sql = "SELECT p.ID
                  FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
                       ON pm.post_id  = p.ID
                      AND pm.meta_key = %s
                 WHERE p.post_type   = %s
                   AND p.post_status = %s
                   AND pm.meta_value IN ({$placeholders})
                 ORDER BY p.ID DESC
                 LIMIT %d";

        /** @var list<numeric-string>|null $rows */
        $rows = $wpdb->get_col($wpdb->prepare($sql, ...$params));

        $ids = [];
        foreach ($rows ?: [] as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        wp_cache_set($genKey, $ids, self::NONOPEN_CACHE_GROUP, self::NONOPEN_CACHE_TTL);
        return $ids;
    }

    /**
     * Bust the {@see getNonOpenGroupIds} cache. Wired in bcc-core.php
     * to the post-meta write hooks for `peepso_group_privacy`. Public
     * because the hook callbacks register against this class
     * statically — no service-locator wiring needed for cache busts.
     */
    public static function bustNonOpenGroupIdsCache(): void
    {
        // wp_cache_incr returns false when the key is uninitialized;
        // initialize-then-incr avoids the no-op on cold caches.
        // Missing-key check is `=== false` (NOT is_int): persistent
        // backends return ints as numeric strings cross-process, and a
        // strict is_int check re-initialized the counter to 0 on every
        // bust — erasing all prior increments instead of adding one.
        if (wp_cache_get(self::NONOPEN_CACHE_KEY_GEN, self::NONOPEN_CACHE_GROUP) === false) {
            wp_cache_set(self::NONOPEN_CACHE_KEY_GEN, 0, self::NONOPEN_CACHE_GROUP);
        }
        wp_cache_incr(self::NONOPEN_CACHE_KEY_GEN, 1, self::NONOPEN_CACHE_GROUP);
    }

    private static function nonOpenCacheKey(int $limit): string
    {
        $gen = wp_cache_get(self::NONOPEN_CACHE_KEY_GEN, self::NONOPEN_CACHE_GROUP);
        if (is_numeric($gen)) {
            // Tolerant read (NOT is_int): persistent backends return
            // ints as numeric strings cross-process; a strict is_int
            // reset the generation to 0 on every read and neutralized
            // bustNonOpenGroupIdsCache(). See
            // HiddenActivityRepository::getGeneration (bcc-trust).
            $gen = (int) $gen;
        } else {
            $gen = 0;
            wp_cache_set(self::NONOPEN_CACHE_KEY_GEN, $gen, self::NONOPEN_CACHE_GROUP);
        }
        // Encode `$limit` into the cache key so callers asking for
        // different bounds don't trample each other (only the default
        // limit is in production paths today; future callers stay safe).
        return self::NONOPEN_CACHE_KEY_LIST . ':' . $limit . ':' . $gen;
    }

    /**
     * Activity heat metrics (post count + last activity timestamp) for
     * a set of groups, restricted to the last $sinceSeconds window.
     *
     * Counts user-authored posts that landed inside each group. PeepSo
     * stores the group association as `peepso_group_id` post-meta on the
     * wp_post (status / photo / GIF / etc. — same pattern the §F3 feed
     * pipeline uses for group-context decoration via
     * {@see \BCC\Trust\Core\Services\Feed\FeedRankingService::hydrateGroupContexts}).
     *
     * The query mirrors the postmeta-JOIN scoping pattern PR 1
     * established in {@see PeepSoActivityRepository::getActivities}'s
     * `$onlyForGroupId` branch — single source of truth for "activities
     * inside a PeepSo group." We join through wp_posts on
     * `act_external_id` (the canonical activity → backing-post FK; the
     * activity table's own PK is `act_id`, which is NOT a wp_posts.ID)
     * and through postmeta to scope the candidate set. No `act_module_id`
     * filter — restricting to a single module would drop legitimate
     * non-status content (photo, GIF, future kinds) from heat counts;
     * the postmeta JOIN already constrains us to the right surface.
     *
     * Only `publish` posts count toward heat; pending / draft / trashed
     * are excluded so reported / removed content doesn't inflate counts.
     *
     * Used by the holder-groups suggestion surface and the groups
     * discovery + detail sort. Groups with zero posts in the window are
     * absent from the map — caller treats absence as cold/zero.
     *
     * Historical note: the prior implementation joined `p.ID = a.act_id`
     * (wrong column — `act_id` is the activity PK) and filtered by
     * `act_module_id = 8` (the PeepSoGroups system module, which carries
     * group-meta events like banner-changed, NOT user content). Both
     * bugs together meant the query returned zero rows for every group,
     * so every surface silently emitted "Quiet" / heat=cold regardless
     * of real activity. Fixed 2026-05-08.
     *
     * @param int[] $groupIds
     * @return array<int, object{posts: int, last_at: string|null}>
     */
    public static function getActivityHeat(array $groupIds, int $sinceSeconds = 7 * DAY_IN_SECONDS): array
    {
        if ($groupIds === []) {
            return [];
        }

        global $wpdb;
        $activities   = $wpdb->prefix . 'peepso_activities';
        $placeholders = implode(',', array_fill(0, count($groupIds), '%s'));
        $cutoff       = gmdate('Y-m-d H:i:s', time() - max(60, $sinceSeconds));

        // Cast group ids to strings so the postmeta `meta_value` (varchar)
        // index is usable. Same convention as PeepSoActivityRepository's
        // `$onlyForGroupId` branch — postmeta's tightest index is
        // (post_id, meta_key), and meta_value comparison stays string-typed.
        $params = [];
        foreach ($groupIds as $id) {
            $params[] = (string) (int) $id;
        }
        $params[] = $cutoff;

        $sql = $wpdb->prepare(
            "SELECT pm.meta_value AS group_id,
                    COUNT(*) AS posts,
                    MAX(p.post_date_gmt) AS last_at
               FROM {$activities} a
         INNER JOIN {$wpdb->posts}    p  ON p.ID       = a.act_external_id
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id  = p.ID
                                         AND pm.meta_key = 'peepso_group_id'
              WHERE pm.meta_value     IN ({$placeholders})
                AND p.post_status     = 'publish'
                AND p.post_date_gmt   >= %s
              GROUP BY pm.meta_value
              LIMIT 500",
            ...$params
        );

        /** @var list<object{group_id: numeric-string, posts: numeric-string, last_at: string|null}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->group_id] = (object) [
                'posts'   => (int) $row->posts,
                'last_at' => $row->last_at,
            ];
        }
        return $map;
    }

    /**
     * Active members of a single group, ordered by role rank then
     * joined_at DESC.
     *
     * Backs the §4.7.7 group-members endpoint behind the Next.js
     * `/groups/[slug]` roster strip. Single-graph rule: this is the
     * canonical seam for "who is in this group" reads — any future
     * caller (admin tools, holder reconciler, etc.) routes through
     * here rather than re-querying `peepso_group_members`.
     *
     * Active-only filter: `gm_user_status LIKE 'member%'` matches
     * `member`, `member_owner`, `member_moderator`, `member_manager`,
     * `member_readonly` (excludes `pending_*`, `banned`, `block_invites`).
     * Same convention as `findUserMemberships` so cross-method counts
     * agree.
     *
     * Order: a `CASE WHEN` synthesises a numeric `role_rank` so the
     * sort happens in SQL, not PHP — owner (1) → moderator/manager (2)
     * → everyone else (3), tiebroken by `gm_create_date DESC` (newest
     * joins first within rank). Ordering in PHP would force a full
     * candidate scan even for paginated reads.
     *
     * Bounded query (§4): `LIMIT $limit OFFSET $offset` with the cap
     * enforced by the caller (max 100 per the §4.7.7 contract). No
     * cache layer — PeepSo owns the write path entirely (member_join /
     * member_leave / member_remove on PeepSoGroupUsers), so a
     * generation counter we'd never bump from our code would surface
     * stale rosters after every PeepSo write. Every existing read
     * method on this repository follows the same uncached-read
     * convention for this reason.
     *
     * @return list<object{user_id: numeric-string, role: string, joined_at: string}>
     */
    public static function listGroupMembers(int $groupId, int $offset, int $limit): array
    {
        if ($groupId <= 0 || $limit <= 0) {
            return [];
        }
        if ($offset < 0) {
            $offset = 0;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        global $wpdb;
        $members = self::membersTable();

        $sql = $wpdb->prepare(
            "SELECT gm_user_id     AS user_id,
                    gm_user_status AS role,
                    gm_create_date AS joined_at,
                    CASE
                        WHEN gm_user_status = 'member_owner'                              THEN 1
                        WHEN gm_user_status IN ('member_moderator', 'member_manager')     THEN 2
                        ELSE 3
                    END AS role_rank
               FROM {$members}
              WHERE gm_group_id = %d
                AND gm_user_status LIKE %s
              ORDER BY role_rank ASC, gm_create_date DESC, gm_id DESC
              LIMIT %d OFFSET %d",
            $groupId,
            self::ACTIVE_MEMBER_STATUS,
            $limit,
            $offset
        );

        /** @var list<object{user_id: numeric-string, role: string, joined_at: string, role_rank: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $out = [];
        foreach ($rows ?: [] as $row) {
            // Drop the synthetic role_rank from the wire shape — caller
            // doesn't need it; the stable contract is {user_id, role, joined_at}.
            $out[] = (object) [
                'user_id'   => $row->user_id,
                'role'      => $row->role,
                'joined_at' => $row->joined_at,
            ];
        }
        return $out;
    }

    /**
     * Total active-member count for one group. Drives the
     * `pagination.total` + `has_more` fields on §4.7.7.
     *
     * NOT reading PeepSo's `peepso_group_members_count` post_meta
     * because (a) that meta counts only `member`-rank rows in some
     * PeepSo paths and the full-active-set count in others — convention
     * varies across PeepSo modules; (b) it's recomputed lazily by
     * PeepSoGroupUsers::update_members_count() on writes, so a
     * subscription burst that races the recompute would show a stale
     * total. A direct COUNT(*) on the same `gm_user_status LIKE 'member%'`
     * filter is one PK-indexed read and stays consistent with
     * `listGroupMembers`.
     */
    public static function countGroupMembers(int $groupId): int
    {
        if ($groupId <= 0) {
            return 0;
        }

        global $wpdb;
        $members = self::membersTable();

        $sql = $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$members}
              WHERE gm_group_id = %d
                AND gm_user_status LIKE %s",
            $groupId,
            self::ACTIVE_MEMBER_STATUS
        );

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Total count for the same filter set as listLocals(). Drives the
     * pagination.total / pagination.total_pages fields.
     */
    public static function countLocals(?string $chain): int
    {
        global $wpdb;

        if ($chain === null) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = %s AND post_title LIKE %s",
                self::POST_TYPE,
                self::POST_STATUS,
                self::LOCAL_TITLE_PATTERN
            ));
        }

        $titleHasChain = '%' . $wpdb->esc_like($chain) . '%';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = %s
               AND post_title LIKE %s AND post_title LIKE %s",
            self::POST_TYPE,
            self::POST_STATUS,
            self::LOCAL_TITLE_PATTERN,
            $titleHasChain
        ));
    }
}
