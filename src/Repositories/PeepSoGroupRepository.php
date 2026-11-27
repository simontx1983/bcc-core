<?php

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to PeepSo Groups (the data layer behind BCC Halls —
 * the auto-provisioned, one-per-chain union halls).
 *
 * Schema reference (verify on PeepSo schema bumps):
 *   - PeepSo Groups are a CPT: wp_posts WHERE post_type = 'peepso-group'
 *   - Membership: {prefix}peepso_group_members (gm_id, gm_user_id,
 *     gm_group_id, gm_user_status, gm_joined, gm_invited_by_id,
 *     gm_accepted_by_id)
 *   - Active members are gm_user_status LIKE 'member%' (excludes
 *     pending_*, banned, block_invites — see install/activate.php)
 *
 * BCC Halls discriminator: the `_bcc_group_kind = 'hall'` post-meta
 * marker, written by HallProvisioningService (bcc-trust) at create
 * time. Chain association is the `_bcc_chain_tag` post-meta (numeric
 * chain id). Neither is parsed from the group title — the title is a
 * display string only ("{Chain} Hall"). This replaced the retired
 * "Local NNN <chain>" title-pattern discriminator.
 *
 * No SELECT *. All queries bounded by LIMIT or aggregate. No writes —
 * PeepSo owns the write path.
 */
final class PeepSoGroupRepository
{
    // The ONE implementation of "run a SELECT and know whether its rows can
    // be trusted" (F-472). This file previously hand-rolled those checks
    // inline in three places; new reads that gate anything use the trait.
    //
    // ⚠ Loading this file BY PATH now also requires ProbesReads.php first —
    // bcc-trust's FeedItemReportVisibilityTest does exactly that, and the
    // trait is not autoloaded there. trust #793 added that line ahead of
    // this change. It is the only by-path loader of this file.
    use ProbesReads;

    private const TABLE_MEMBERS_SUFFIX = 'peepso_group_members';
    private const POST_TYPE            = 'peepso-group';
    private const POST_STATUS          = 'publish';
    private const META_KIND            = '_bcc_group_kind';
    private const KIND_HALL            = 'hall'; // Hall discriminator (paired with HallRepository::KIND_HALL)
    private const META_CHAIN_TAG       = '_bcc_chain_tag'; // numeric chain id, resolver-aware (see ChainRepository::resolveSlugsForGroups)
    private const ACTIVE_MEMBER_STATUS = 'member%'; // matches member, member_moderator, member_manager, member_owner, member_readonly

    /**
     * wp_usermeta key for a user's primary-Hall pointer. Hardcoded here
     * rather than imported from bcc-trust's HallsService::META_PRIMARY_GROUP
     * because bcc-core cannot depend on bcc-trust. The two declarations are
     * intentionally paired: changing one without the other is a §VIII
     * pattern violation.
     */
    private const PRIMARY_HALL_META_KEY = 'bcc_primary_hall_group_id';

    // Non-open privacy per PeepSo's `peepso_group_privacy` post-meta:
    //   0 = public (open), 1 = closed, 2 = secret. NFT-gated groups carry
    //   privacy = 1 (closed) plus a sidecar marker — they are a strict
    //   subset of "non-open." The deny lists below read it through
    //   PeepSoGroupPrivacy::nonOpenSql(), never a string compare.

    // Cache for getNonOpenGroupIdsStrict — generation-counter pattern per §5.
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

    /** Longest name query the list reads use, in characters; the rest is ignored. */
    public const NAME_QUERY_MAX = 100;

    /**
     * The optional name filter the group list reads share: a substring match
     * on the group's title, prepared, with LIKE's wildcards in the query
     * escaped so they match themselves. An empty or all-space query adds
     * nothing, so the read is exactly what it was without one. A query that
     * is not valid UTF-8 matches nothing.
     *
     * @return array{0: string, 1: list<string>} the fragment (with its leading " AND ") and its argument
     */
    private static function nameQueryClause(string $nameQuery, string $titleColumn): array
    {
        global $wpdb;

        $query = trim($nameQuery);
        if ($query === '') {
            return ['', []];
        }
        if (preg_match('/^.{0,' . self::NAME_QUERY_MAX . '}/su', $query, $m) !== 1) {
            return [' AND 1 = 0', []];
        }

        return [" AND {$titleColumn} LIKE %s", ['%' . $wpdb->esc_like($m[0]) . '%']];
    }

    /** listHalls() / countHalls() `$hallKind`: a Hall connected to a chain. */
    public const HALL_KIND_CHAIN = 'chain';

    /** listHalls() / countHalls() `$hallKind`: a Hall about a topic, with no chain. */
    public const HALL_KIND_TOPIC = 'topic';

    /** A topic Hall's topic slug (paired with bcc-trust's HallRepository, which writes it). */
    private const META_HALL_TOPIC = '_bcc_hall_topic';

    /** A chain tag names a chain only as a positive id; the writers store nothing else. */
    private const CHAIN_TAG_PATTERN = '^[1-9][0-9]*$';

    /**
     * The optional Hall-kind filter listHalls() and countHalls() share, so a
     * page and its total agree:
     *  - HALL_KIND_CHAIN: the Hall has a `_bcc_chain_tag` row naming a chain;
     *  - HALL_KIND_TOPIC: the Hall has a non-empty `_bcc_hall_topic` row and
     *    no such chain tag.
     * The two never overlap. A Hall with neither is in neither filtered
     * list and still in the unfiltered one: nothing is inferred. null adds
     * nothing, so the read is exactly what it was without a kind. Any other
     * value matches nothing.
     *
     * @return array{0: string, 1: list<string>} the fragment (with its leading " AND ") and its arguments
     */
    private static function hallKindClause(?string $hallKind, string $idColumn): array
    {
        global $wpdb;

        if ($hallKind === null) {
            return ['', []];
        }

        $chain     = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} hk_chain
                               WHERE hk_chain.post_id = {$idColumn}
                                 AND hk_chain.meta_key = %s
                                 AND hk_chain.meta_value REGEXP %s)";
        $chainArgs = [self::META_CHAIN_TAG, self::CHAIN_TAG_PATTERN];

        return match ($hallKind) {
            self::HALL_KIND_CHAIN => [" AND {$chain}", $chainArgs],
            self::HALL_KIND_TOPIC => [
                " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} hk_topic
                               WHERE hk_topic.post_id = {$idColumn}
                                 AND hk_topic.meta_key = %s
                                 AND hk_topic.meta_value <> '')
                  AND NOT {$chain}",
                [self::META_HALL_TOPIC, ...$chainArgs],
            ],
            default => [' AND 1 = 0', []],
        };
    }

    /**
     * Halls (`_bcc_group_kind = 'hall'`), with member counts. Optionally
     * filtered to a single chain via the `_bcc_chain_tag` numeric-chain-id
     * post-meta. Callers resolve a chain slug to its id (ChainRepository)
     * before passing it — bcc-core does not depend on the chain registry.
     * Optionally filtered by name ({@see nameQueryClause()}) and by Hall
     * kind ({@see hallKindClause()}); countHalls() takes the same filters,
     * so a page and its total agree.
     *
     * @param self::HALL_KIND_*|null $hallKind
     * @return list<object{
     *   id: numeric-string,
     *   post_name: string,
     *   post_title: string,
     *   member_count: numeric-string
     * }>
     */
    public static function listHalls(?int $chainId, int $offset, int $limit, string $nameQuery = '', ?string $hallKind = null): array
    {
        global $wpdb;
        $members = self::membersTable();
        [$nameSql, $nameArgs] = self::nameQueryClause($nameQuery, 'p.post_title');
        [$kindSql, $kindArgs] = self::hallKindClause($hallKind, 'p.ID');

        // INNER JOIN on the hall-kind meta is the discriminator; a single
        // meta row per post keeps COUNT(gm.gm_id) accurate under GROUP BY.
        $chainJoin = $chainId !== null
            ? " INNER JOIN {$wpdb->postmeta} pm_chain ON pm_chain.post_id = p.ID
                                                     AND pm_chain.meta_key = %s
                                                     AND pm_chain.meta_value = %d"
            : '';

        $sql = "SELECT p.ID AS id, p.post_name, p.post_title,
                       COALESCE(COUNT(gm.gm_id), 0) AS member_count
                  FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = p.ID
                                                AND pm_kind.meta_key = %s
                                                AND pm_kind.meta_value = %s"
             . $chainJoin
             . " LEFT JOIN {$members} gm ON gm.gm_group_id = p.ID
                                        AND gm.gm_user_status LIKE %s
                 WHERE p.post_type = %s
                   AND p.post_status = %s{$nameSql}{$kindSql}
                 GROUP BY p.ID
                 ORDER BY p.post_title ASC
                 LIMIT %d OFFSET %d";

        $params = [self::META_KIND, self::KIND_HALL];
        if ($chainId !== null) {
            $params[] = self::META_CHAIN_TAG;
            $params[] = $chainId;
        }
        $params[] = self::ACTIVE_MEMBER_STATUS;
        $params[] = self::POST_TYPE;
        $params[] = self::POST_STATUS;
        array_push($params, ...$nameArgs, ...$kindArgs);
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return $rows ?: [];
    }

    /**
     * Find a single Hall by its post_name (slug). Same row shape as
     * `listHalls` — id + slug + title + member_count — so the service
     * layer can reuse the same render path for the detail page that it
     * uses for the directory.
     *
     * Returns null when no row matches the slug + hall-kind filter.
     * The slug uniqueness is implicit (post_name is per-type-unique
     * in WP) but we still LIMIT 1 defensively.
     *
     * @return object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null
     */
    public static function findHallBySlug(string $slug): ?object
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
         INNER JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = p.ID
                                             AND pm_kind.meta_key = %s
                                             AND pm_kind.meta_value = %s
          LEFT JOIN {$members} gm ON gm.gm_group_id = p.ID
                                 AND gm.gm_user_status LIKE %s
              WHERE p.post_type = %s
                AND p.post_status = %s
                AND p.post_name = %s
              GROUP BY p.ID
              LIMIT 1",
            self::META_KIND,
            self::KIND_HALL,
            self::ACTIVE_MEMBER_STATUS,
            self::POST_TYPE,
            self::POST_STATUS,
            $slug
        );

        /** @var object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null $row */
        $row = $wpdb->get_row($sql);
        return $row;
    }

    /**
     * Find a single Hall by its group id. Same row shape + hall-kind
     * filter as `findHallBySlug` — used by the join/leave/set-primary write
     * paths to verify the target group is actually a BCC Hall before
     * mutating membership (rather than e.g. a PeepSo support group that
     * happens to share the id space).
     *
     * @return object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null
     */
    public static function findHallById(int $groupId): ?object
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
         INNER JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = p.ID
                                             AND pm_kind.meta_key = %s
                                             AND pm_kind.meta_value = %s
          LEFT JOIN {$members} gm ON gm.gm_group_id = p.ID
                                 AND gm.gm_user_status LIKE %s
              WHERE p.post_type = %s
                AND p.post_status = %s
                AND p.ID = %d
              GROUP BY p.ID
              LIMIT 1",
            self::META_KIND,
            self::KIND_HALL,
            self::ACTIVE_MEMBER_STATUS,
            self::POST_TYPE,
            self::POST_STATUS,
            $groupId
        );

        /** @var object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null $row */
        $row = $wpdb->get_row($sql);
        return $row;
    }

    /**
     * Bulk-fetch group post info (id, slug, title, content, member_count)
     * for a set of group_ids. Used by the User view-model's `halls`
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
     * Unlike `findHallBySlug` (Halls-only — hall-kind filter applied),
     * this method returns ANY published peepso-group regardless of kind
     * — used by the cross-kind /bcc/v1/groups/{slug} detail endpoint to
     * resolve nft / hall / system / user groups uniformly.
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
     * Batched primary-Hall lookup. For a set of user_ids, returns the
     * group post info (same shape as `findManyByIds`) of each user's
     * primary Hall — the group pointed to by their
     * `bcc_primary_hall_group_id` user_meta. Users without a primary
     * (or whose pointer no longer resolves to an active Hall) are
     * absent from the map.
     *
     * Used by list-shape consumers (e.g. /members directory) where
     * 24 rows × `get_user_meta` + 24 × `findHallById` would N+1.
     * Replaces ~48 sequential calls with two SQLs.
     *
     * Note on scope: this returns only the *primary* Hall — the row
     * the user has elected as their home Hall. Callers that need the
     * full membership list should use `findUserMemberships` per-user
     * (or build a batched sibling if a list surface needs it). The
     * /members card only shows the primary, so that's all we batch.
     *
     * Callers show it only through bcc-trust's published-Hall check
     * (HallRepository::primaryHallsForUsers()), never directly.
     *
     * Empty `$userIds` short-circuits — no SQL.
     *
     * @param int[] $userIds Bounded by caller (directory `per_page`
     *                       cap, e.g. 50). The user_meta + posts IN
     *                       clauses scale linearly.
     * @return array<int, object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}>
     *         user_id → primary-Hall group post info
     */
    public static function getPrimaryHallForUsers(array $userIds): array
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

        // Step 1: pull the bcc_primary_hall_group_id meta for every
        // user in one SQL. Skips users with no row (they don't have a
        // primary set).
        /** @var list<array{user_id: string, meta_value: string}> $metaRows */
        $metaRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_value
                   FROM {$wpdb->usermeta}
                  WHERE meta_key = %s
                    AND user_id IN ({$placeholders})",
                self::PRIMARY_HALL_META_KEY,
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
        // helper. Groups that no longer exist (deleted Halls) drop
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
     * Inverse of getPrimaryHallForUsers. Given a Hall group_id, return
     * every user who's designated it as primary AND is still an active,
     * unbanned member of it. Used by the "post in your primary Hall"
     * notification dispatcher (bcc-trust) to fan out to the recipient set.
     *
     * Membership is read from peepso_group_members, not inferred from the
     * pointer: a removal or a ban done through PeepSo never clears the
     * pointer. EXISTS / NOT EXISTS, so a duplicated (user, group) pair can
     * neither repeat a user nor hide a ban.
     *
     * The meta key string is hardcoded here rather than imported from
     * bcc-trust's HallsService::META_PRIMARY_GROUP because bcc-core
     * cannot depend on bcc-trust. The two declarations are intentionally
     * paired: changing one without the other is a §VIII pattern
     * violation — see getPrimaryHallForUsers above for the same
     * coupling note.
     *
     * Bounded by $limit per §5 (no unbounded SELECT). The default 1000
     * is well above today's typical primary-Hall membership; revisit
     * with cursor pagination when a real Hall crosses ~500 primary
     * members.
     *
     * Order: server-defined (by user_id ascending). Deduped via the
     * meta-key uniqueness contract — `bcc_primary_hall_group_id` is
     * a singleton per user (one row max, written via update_user_meta
     * in HallsService::setPrimaryHall).
     *
     * @return list<int> user IDs (positive integers), bounded by $limit
     */
    public static function findUsersByPrimaryHall(int $groupId, int $limit = 1000): array
    {
        if ($groupId <= 0 || $limit <= 0) {
            return [];
        }

        global $wpdb;
        $members = self::membersTable();

        // The pointer alone is not membership: only BCC's own leaveHall()
        // clears it, so a member removed or banned through PeepSo kept the
        // pointer and kept getting the Hall's post bells. A recipient must
        // also hold an ACTIVE (member%) row and NO banned row: a ban
        // outranks any stray active row beside it (F-354's rank).
        /** @var list<string>|null $rows */
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT um.user_id
                   FROM {$wpdb->usermeta} um
                  WHERE um.meta_key = %s
                    AND um.meta_value = %s
                    AND EXISTS (
                        SELECT 1
                          FROM {$members} gm
                         WHERE gm.gm_group_id = %d
                           AND gm.gm_user_id = um.user_id
                           AND gm.gm_user_status LIKE %s
                    )
                    AND NOT EXISTS (
                        SELECT 1
                          FROM {$members} gb
                         WHERE gb.gm_group_id = %d
                           AND gb.gm_user_id = um.user_id
                           AND gb.gm_user_status = 'banned'
                    )
                  ORDER BY um.user_id ASC
                  LIMIT %d",
                self::PRIMARY_HALL_META_KEY,
                (string) $groupId,
                $groupId,
                self::ACTIVE_MEMBER_STATUS,
                $groupId,
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
     * group_ids. Used by HallsService to enrich the catalog with
     * per-row viewer_membership without an N+1.
     *
     * Inactive PeepSo statuses (pending_*, banned, block_invites) are
     * excluded — only rows where gm_user_status starts with 'member'
     * surface here.
     *
     * Clock-split note (BCC norm = UTC): `gm_joined` is a TIMESTAMP and
     * MySQL renders it in the SESSION time zone (SYSTEM = Central on
     * prod/staging), so the `joined_at` wall-time string is NOT UTC —
     * consumers that stamp it as UTC are 5–6h wrong. `gm_joined_utc`
     * (UNIX_TIMESTAMP epoch seconds) is tz-proof; consumers MUST prefer
     * it and format in PHP. `joined_at` stays for back-compat only
     * (bcc-trust switches in a paired PR; deploy order core-first).
     *
     * ⚠ `user_status` is the MEMBERSHIP ROLE, and it is here because the
     * `LIKE 'member%'` filter above is BROADER than "may post". It admits
     * `member_readonly`, which reads but cannot contribute. A caller that
     * only needs "is this viewer a member" can keep using row PRESENCE and
     * ignore this field — that is every existing caller. A caller deciding
     * whether someone may WRITE must read `user_status` and compare it
     * against the posting-capable set, because presence alone would answer
     * yes for a read-only member. Returning it here keeps that decision a
     * single batched query instead of one per group.
     *
     * @param int[] $groupIds
     * @return array<int, object{group_id: numeric-string, joined_at: string, gm_joined_utc: numeric-string, user_status: string}>
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
            "SELECT gm_group_id AS group_id,
                    gm_joined AS joined_at,
                    UNIX_TIMESTAMP(gm_joined) AS gm_joined_utc,
                    gm_user_status AS user_status
               FROM {$members}
              WHERE gm_user_id = %d
                AND gm_group_id IN ({$placeholders})
                AND gm_user_status LIKE %s
              LIMIT 200",
            ...$args
        );

        // ⚠ `|null` is correct HERE, unlike for get_col: get_results returns
        // null when the QUERY STRING is empty, and `last_result` (so, []) on
        // a query error. Either way `?: []` below yields no memberships, so
        // a failed lookup reads as "not a member" — which is the closed
        // direction for every caller, including the posting decision.
        /** @var list<object{group_id: numeric-string, joined_at: string, gm_joined_utc: numeric-string, user_status: string}>|null $rows */
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
     *
     * A duplicated pair (no unique index) resolves by {@see statusRank()},
     * the same row {@see findUserMembershipStatuses()} reports (F-354).
     */
    public static function getMembershipStatus(int $userId, int $groupId): ?string
    {
        if ($userId <= 0 || $groupId <= 0) {
            return null;
        }

        global $wpdb;
        $members = self::membersTable();

        /** @var list<string> $statuses */
        $statuses = $wpdb->get_col($wpdb->prepare(
            "SELECT gm_user_status
               FROM {$members}
              WHERE gm_user_id  = %d
                AND gm_group_id = %d
              ORDER BY gm_id ASC
              LIMIT %d",
            $userId,
            $groupId,
            self::PAIR_ROW_CAP
        ));

        return self::rankedStatus(array_map('strval', $statuses));
    }

    /**
     * The user's raw gm_user_status in each of $groupIds — ANY status
     * (member%, pending_admin, pending_user, banned, block_invites); a group
     * with no row is absent from the result. One bounded query (≤ 200 ids,
     * extra ids are ignored), for list surfaces (community cards, the
     * profile Groups tab) that need the same join verdict as the group page
     * without one read per group (F-244).
     *
     * FAIL-CLOSED: null when the read did not run, never [] — callers must
     * not guess a Join from a failed read.
     *
     * @param int[] $groupIds
     * @return array<int, string>|null group id => gm_user_status
     */
    public static function findUserMembershipStatuses(int $userId, array $groupIds): ?array
    {
        $groupIds = array_slice(
            array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0))),
            0,
            200
        );
        if ($userId <= 0 || $groupIds === []) {
            return [];
        }

        global $wpdb;
        $members      = self::membersTable();
        $placeholders = implode(',', array_fill(0, count($groupIds), '%d'));

        $ok = $wpdb->query($wpdb->prepare(
            "SELECT gm_group_id, gm_user_status
               FROM {$members}
              WHERE gm_user_id = %d
                AND gm_group_id IN ({$placeholders})
              ORDER BY (gm_user_status = 'banned') DESC, gm_id ASC
              LIMIT %d",
            // 2× the ids: with no unique (user, group) index a pair can have
            // a duplicate row, and a limit equal to the id count would let
            // duplicates push a real group past it — read as "no row".
            //
            // Banned rows FIRST: the limit is shared by every pair, so a pair
            // with three or more rows can push its own NEWER ban past it and
            // read as a member (security review, F-416). A ban therefore never
            // falls outside the window while any active row is inside it.
            // Within each class gm_id still orders the rows, so the tie-break
            // below is unchanged.
            ...array_merge([$userId], $groupIds, [2 * count($groupIds)])
        ));
        if ($ok === false || (string) $wpdb->last_error !== '') {
            return null;
        }

        // F-354: no unique (user, group) index, so a pair can carry a stale
        // duplicate. Rows arrive bans first, then in gm_id order; per group
        // the best-ranked row wins and the lowest gm_id breaks a tie, so the answer never
        // depends on which duplicate happened to be inserted first:
        //   banned  >  member_owner  >  other active (member%)  >  anything else (pending_*, …)
        // A ban outranks membership (fail closed, and the write doors
        // refuse on any banned row); an owner row outranks another active
        // row (leave() must see the owner, or member_leave() deletes the
        // owner row with it); membership outranks a stale pending or invite
        // row left beside it.
        $out  = [];
        $rank = [];
        foreach (is_array($wpdb->last_result) ? $wpdb->last_result : [] as $row) {
            if (!isset($row->gm_group_id, $row->gm_user_status)) {
                continue;
            }
            $groupId = (int) $row->gm_group_id;
            $status  = (string) $row->gm_user_status;
            $r       = self::statusRank($status);
            if (!isset($rank[$groupId]) || $r < $rank[$groupId]) {
                $rank[$groupId] = $r;
                $out[$groupId]  = $status;
            }
        }
        return $out;
    }

    /**
     * The pair's winning status: best {@see statusRank()}, the first (lowest
     * gm_id) row breaking a tie. null for no rows.
     *
     * @param list<string> $statuses in gm_id order
     */
    private static function rankedStatus(array $statuses): ?string
    {
        $best = null;
        foreach ($statuses as $status) {
            if ($best === null || self::statusRank($status) < self::statusRank($best)) {
                $best = $status;
            }
        }
        return $best;
    }

    /**
     * Rows read for ONE (user, group) pair. A pair normally has one row; the
     * cap bounds the read while leaving room for duplicates (F-354).
     */
    private const PAIR_ROW_CAP = 10;

    /**
     * F-354 duplicate-row precedence: lower wins.
     *
     *   banned  >  member_owner  >  other active (member%)  >  anything else
     *
     * The owner row outranks another active row because PeepSo's
     * member_leave() deletes EVERY row of the pair: read as 'member', an
     * owner with a stray member row passed PeepSoGroupWriter::leave()'s owner
     * refusal (and GroupLeaveGuard's), and the group lost its owner row. The
     * same order the bcc-trust page read uses (ban, then owner, then oldest).
     */
    private static function statusRank(string $status): int
    {
        if ($status === 'banned') {
            return 0;
        }
        if ($status === 'member_owner') {
            return 1;
        }
        return str_starts_with($status, 'member') ? 2 : 3;
    }

    /**
     * Which of $groupIds the user is BANNED from (gm_user_status =
     * 'banned'). One bounded query; the holder and delegator discovery
     * lists use it so a banned group is shown as "removed" instead of
     * offering a Join that the writer will refuse.
     *
     * A failed read returns [] ("no bans"). That is safe ONLY because this
     * is display: the join door itself (PeepSoGroupWriter::join) refuses a
     * banned row and a failed read on its own. Never gate a write on this.
     *
     * @param int[] $groupIds
     * @return list<int>
     */
    public static function findUserBannedGroupIds(int $userId, array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0)));
        if ($userId <= 0 || $groupIds === []) {
            return [];
        }

        global $wpdb;
        $members      = self::membersTable();
        $placeholders = implode(',', array_fill(0, count($groupIds), '%d'));

        /** @var list<numeric-string> $rows */
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT gm_group_id
               FROM {$members}
              WHERE gm_user_id = %d
                AND gm_group_id IN ({$placeholders})
                AND gm_user_status = 'banned'
              LIMIT 200",
            ...array_merge([$userId], $groupIds)
        ));

        return array_values(array_map('intval', $rows));
    }

    /**
     * getMembershipStatus() for the group WRITE doors: the same read, but a
     * read that did not run returns FALSE instead of reading as "no row".
     *
     * Returns the gm_user_status string, null when the user has no row in
     * the group, or false when the read failed. PeepSoGroupWriter::join()
     * and ::leave() decide from this whether a 'banned' row is present; a
     * failure that read as null would let join() flip a ban back to
     * membership (PeepSo's member_join() modifies ANY existing row) and
     * let leave() raw-DELETE the banned row. Both refuse on false.
     *
     * Uncached, bounded by the (user, group) pair (LIMIT PAIR_ROW_CAP).
     * A duplicated pair resolves by {@see statusRank()} — a banned row
     * always wins — so the doors decide from the same row the list
     * surfaces show (F-354).
     */
    public static function getMembershipStatusOrFail(int $userId, int $groupId): string|null|false
    {
        if ($userId <= 0 || $groupId <= 0) {
            return null;
        }

        global $wpdb;
        $members = self::membersTable();

        // query() + last_result, not get_var(): get_var() returns null for
        // a failed read too, which is the ambiguity this method exists to
        // remove. query() returns false on error; last_error is a second
        // signal (query() can return false before flush()).
        $queryOk = $wpdb->query($wpdb->prepare(
            "SELECT gm_user_status
               FROM {$members}
              WHERE gm_user_id  = %d
                AND gm_group_id = %d
              ORDER BY gm_id ASC
              LIMIT %d",
            $userId,
            $groupId,
            self::PAIR_ROW_CAP
        ));
        if ($queryOk === false || (string) $wpdb->last_error !== '') {
            return false;
        }

        $statuses = [];
        foreach (is_array($wpdb->last_result) ? $wpdb->last_result : [] as $row) {
            if (isset($row->gm_user_status)) {
                $statuses[] = (string) $row->gm_user_status;
            }
        }
        return self::rankedStatus($statuses);
    }

    /**
     * How many User-kind (member-created) groups this user CURRENTLY
     * owns. Backs the §21.2 ownership caps (Rank Phase 7): the
     * CapabilityResolver's custody gate compares this count against the
     * per-rank cap from rank-scoring config.
     *
     * "User-kind" = the group post carries NO `_bcc_group_kind` post
     * meta (LEFT JOIN … IS NULL). Kinded groups — hall / holders /
     * delegators / system — are infra- or gate-provisioned and NEVER
     * count toward a member's cap.
     *
     * Ownership = gm_user_status = 'member_owner' on a published
     * peepso-group post. Single aggregate COUNT (§4 bounded).
     * INTENTIONALLY UNCACHED — this feeds a custody security gate and
     * PeepSo owns ownership-changing write paths (its own UI role
     * changes) that fire no hook BCC can bust on; same uncached-read
     * convention as {@see getUserMemberGroupIds}.
     */
    public static function countOwnedUserGroups(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var(self::ownedUserGroupsCountSql($userId));
    }

    /**
     * The same count, but it tells you when it DOES NOT KNOW.
     *
     * `?int` — null means the read could not be trusted. A real answer is
     * an int, and 0 is a real answer ("owns none").
     *
     * ⚠⚠ WHY THIS EXISTS. {@see countOwnedUserGroups} returns `int`, and
     * `get_var()` answers null for a failed read, which `(int)` turns into
     * **0** — indistinguishable from "owns none". A caller comparing the
     * count against a cap therefore reads a failed read as the most
     * permissive possible answer, and the §21.2 custody cap is a WRITE
     * authorization: whatever it lets through stays granted after the fault
     * clears. {@see \BCC\Trust\Core\Services\Capability\CapabilityResolver}
     * consumes this one and refuses when it is null.
     *
     * Read through {@see ProbesReads::trustedRows()}, which checks
     * `query()`'s own return as well as `last_error` — `get_var()` discards
     * that return, and `wpdb::query()` has failure paths that set no
     * `last_error`.
     *
     * ⚠ A COUNT always returns exactly one row, so zero rows is not an
     * empty result — it means the read cannot be relied on, and that is
     * null too rather than 0.
     *
     * Same lenient/strict pairing as {@see getMembershipStatusOrFail}.
     * INTENTIONALLY UNCACHED, for the reason on the lenient twin.
     */
    public static function countOwnedUserGroupsOrFail(int $userId): ?int
    {
        // Not a read failure: no read is needed to know the answer.
        if ($userId <= 0) {
            return 0;
        }

        $rows = self::trustedRows(self::ownedUserGroupsCountSql($userId));
        if ($rows === null) {
            return null;
        }

        $row = $rows[0] ?? null;
        if (!is_object($row) || !isset($row->owned_count)) {
            return null;
        }

        return (int) $row->owned_count;
    }

    /**
     * The one COUNT both twins run, so they cannot drift.
     *
     * ⚠ Aliased `owned_count` because the strict twin reads rows as
     * objects; an unaliased `COUNT(*)` arrives as a property named
     * `COUNT(*)`. The alias changes nothing for `get_var()`, which takes
     * the first column whatever it is called.
     */
    private static function ownedUserGroupsCountSql(int $userId): string
    {
        global $wpdb;
        $members = self::membersTable();

        return (string) $wpdb->prepare(
            "SELECT COUNT(*) AS owned_count
               FROM {$members} gm
         INNER JOIN {$wpdb->posts} p ON p.ID = gm.gm_group_id
          LEFT JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = p.ID
                                             AND pm_kind.meta_key = %s
              WHERE gm.gm_user_id     = %d
                AND gm.gm_user_status = %s
                AND p.post_type       = %s
                AND p.post_status     = %s
                AND pm_kind.meta_value IS NULL",
            self::META_KIND,
            $userId,
            'member_owner',
            self::POST_TYPE,
            self::POST_STATUS
        );
    }

    /**
     * IDs of all browsable peepso-groups — published, post_type =
     * 'peepso-group', not `is_secret`. Drives the discovery endpoint.
     * Optionally filtered by name ({@see nameQueryClause()}), in the same
     * statement as the secret exclusion, so a name never finds a secret group.
     *
     * Closed groups ARE included: per the plan, a non-member sees that
     * the group exists (encourages buying in for NFT-gated cases) but
     * PeepSo's privacy keeps content private. Secret groups stay hidden
     * from anyone who isn't already a member.
     *
     * @return list<int>
     */
    public static function listBrowsableGroupIds(int $limit = 500, string $nameQuery = ''): array
    {
        global $wpdb;
        [$nameSql, $nameArgs] = self::nameQueryClause($nameQuery, 'p.post_title');

        // F-429: the shared rule, not an inline check. The LEFT JOIN form
        // this replaced listed a group whenever ANY of its privacy rows was
        // not 2 — so a group carrying both '0' and '2' was browsable — and
        // returned it once per row. NOT EXISTS needs no join, so dropping
        // the join removes the duplicate rows along with the leak.
        $notSecret = PeepSoGroupPrivacy::notSecretSql('p.ID');

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
              WHERE p.post_type   = %s
                AND p.post_status = %s
                AND {$notSecret}{$nameSql}
              ORDER BY p.ID DESC
              LIMIT %d",
            self::POST_TYPE,
            self::POST_STATUS,
            ...[...$nameArgs, $limit]
        ));

        return array_values(array_map('intval', $rows ?: []));
    }

    /** Largest page {@see openGroupsAfter()} answers. */
    public const OPEN_GROUPS_PAGE_MAX = 1000;

    /**
     * F-518: one keyset page of OPEN groups for a public listing (the
     * sitemap): published peepso-groups with a slug whose privacy is
     * explicitly open ({@see PeepSoGroupPrivacy::openSql()}), ascending by
     * id, strictly after `$afterId`.
     *
     * Not {@see listBrowsableGroupIds()}: that one is the discovery list,
     * which shows CLOSED groups too (a product decision for browsing), reads
     * newest-first with a fixed cap, and cannot tell a failed read from an
     * empty one. A public listing needs open groups only, every one of them
     * reachable page by page, and a failure it can refuse on.
     *
     * `is_hall` is the `_bcc_group_kind = 'hall'` discriminator, the one
     * bcc-trust's GroupContextResolver maps to GroupType::Hall, so a caller
     * can pick `/halls/` or `/communities/` without a second read.
     *
     * ⚠ Read through ProbesReads: null means the read could not be
     * trusted, and the caller must fail closed (list nothing), never treat
     * it as "no open groups". An out-of-range `$limit` (1..1000) or a
     * negative `$afterId` is refused with null rather than clamped.
     *
     * @return list<array{id: int, slug: string, is_hall: bool}>|null
     */
    public static function openGroupsAfter(int $afterId, int $limit): ?array
    {
        if ($afterId < 0 || $limit < 1 || $limit > self::OPEN_GROUPS_PAGE_MAX) {
            return null;
        }

        global $wpdb;

        $open = PeepSoGroupPrivacy::openSql('p.ID');

        $rows = self::trustedRowsAssoc((string) $wpdb->prepare(
            "SELECT p.ID AS id,
                    p.post_name AS slug,
                    EXISTS (SELECT 1 FROM {$wpdb->postmeta} kind
                             WHERE kind.post_id    = p.ID
                               AND kind.meta_key   = %s
                               AND kind.meta_value = %s) AS is_hall
               FROM {$wpdb->posts} p
              WHERE p.post_type   = %s
                AND p.post_status = %s
                AND p.post_name  <> ''
                AND p.ID > %d
                AND {$open}
              ORDER BY p.ID ASC
              LIMIT %d",
            self::META_KIND,
            self::KIND_HALL,
            self::POST_TYPE,
            self::POST_STATUS,
            $afterId,
            $limit
        ));
        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $id   = (int) ($row['id'] ?? 0);
            $slug = (string) ($row['slug'] ?? '');
            if ($id <= 0 || $slug === '') {
                continue;
            }
            $out[] = [
                'id'      => $id,
                'slug'    => $slug,
                'is_hall' => (int) ($row['is_hall'] ?? 0) === 1,
            ];
        }

        return $out;
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
     * This feeds bcc-trust's RestrictedGroups (the profile Blog tab's group
     * gate), which
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
     * The PUBLISHED groups the user is an active member of, for a read that
     * shows those groups' MEMBER content (the home feed's "My Groups" scope,
     * F-416). Most recently joined first, at most `$limit` (capped at 200).
     *
     * Stricter than {@see getUserMemberGroupIds()} in three ways, each one
     * load-bearing for a member read:
     *   - F-354: a pair can carry a stale duplicate row, so a `member%` row
     *     alone does not prove membership. Each candidate is re-read through
     *     {@see findUserMembershipStatuses()} and kept only when its RANKED
     *     status is active: a `banned` row beside a `member` row wins, and a
     *     group is out;
     *   - only published `peepso-group` posts: a trashed or drafted group's
     *     member rows do not reach a feed;
     *   - a read that did not run answers NULL, never `[]`. `[]` is "a member
     *     of nothing" (an empty feed); null lets the caller answer "try again"
     *     instead of showing a member an empty page during an outage.
     *
     * Uncached for the same reason as getUserMemberGroupIds(): a stale
     * "still a member" would show a removed or banned member the group's
     * members-only posts.
     *
     * @return list<int>|null null when either read failed
     */
    public static function getActiveMemberGroupIdsOrFail(int $userId, int $limit = 200): ?array
    {
        if ($userId <= 0) {
            return [];
        }
        // findUserMembershipStatuses() ranks at most 200 ids.
        $limit = max(1, min(200, $limit));

        global $wpdb;
        $members = self::membersTable();

        // GROUP BY, not DISTINCT: the order is the latest join of the pair,
        // which DISTINCT could not sort by on MySQL 8 (it rejects ORDER BY
        // over a column that is not selected). gm_group_id breaks a tie.
        $ok = $wpdb->query($wpdb->prepare(
            "SELECT gm.gm_group_id
               FROM {$members} gm
              INNER JOIN {$wpdb->posts} p ON p.ID = gm.gm_group_id
              WHERE gm.gm_user_id     = %d
                AND gm.gm_user_status LIKE %s
                AND p.post_type       = %s
                AND p.post_status     = %s
              GROUP BY gm.gm_group_id
              ORDER BY MAX(gm.gm_joined) DESC, gm.gm_group_id DESC
              LIMIT %d",
            $userId,
            self::ACTIVE_MEMBER_STATUS,
            self::POST_TYPE,
            self::POST_STATUS,
            $limit
        ));
        if ($ok === false || (string) $wpdb->last_error !== '') {
            return null;
        }

        $candidates = [];
        foreach (is_array($wpdb->last_result) ? $wpdb->last_result : [] as $row) {
            if (isset($row->gm_group_id)) {
                $candidates[] = (int) $row->gm_group_id;
            }
        }
        if ($candidates === []) {
            return [];
        }

        $statuses = self::findUserMembershipStatuses($userId, $candidates);
        if ($statuses === null) {
            return null;
        }

        $active = [];
        foreach ($candidates as $groupId) {
            $status = $statuses[$groupId] ?? null;
            if ($status !== null && str_starts_with($status, 'member')) {
                $active[] = $groupId;
            }
        }
        return $active;
    }

    /**
     * Co-members of a set of groups: for every active member of any
     * group in `$groupIds`, return how many of those groups they share
     * and one representative shared group_id. Drives the who-to-follow
     * recommender's "in a Hall / holder community with you" affinity
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
     * name and classifies it (Hall vs holder community) via the group
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
     * IDs of every peepso-group whose privacy is NOT open — a
     * `peepso_group_privacy` row that reads 1 or 2, i.e. closed OR secret
     * (NFT-gated groups are a closed-privacy subset) — through
     * {@see PeepSoGroupPrivacy::nonOpenSql()}, or NULL when the answer
     * cannot be proven complete. Its callers use it as a deny list (the
     * F-23 syndication gate, bcc-trust's report and blog gates), so a short
     * list is never served as a complete one.
     *
     * It returns NULL for both failure modes:
     *
     *   - the query errored (`query()` false or `last_error` set; `get_col`
     *     alone cannot tell, it returns `[]`). Distinct from empty, and
     *     not cached, so one bad query cannot hold the answer for the
     *     cache TTL.
     *   - more rows exist than the caller asked for. Detected by asking
     *     for `$limit + 1` and seeing the extra row come back, rather
     *     than by guessing from a full-looking page. The underlying
     *     query orders by `p.ID DESC`, so truncation silently drops the
     *     OLDEST non-open groups — the ones most likely to be
     *     established private communities.
     *
     * An empty return is therefore trustworthy: it means the query ran
     * and no group is non-open.
     *
     * ⚠ It IGNORES `post_status`: a trashed or draft secret group is
     * still secret, so it stays on the deny list.
     *
     * ⚠⚠ CONSEQUENCE FOR THE CAP: trashed and draft non-open groups now
     * count toward `$limit`. A site that has accumulated many trashed
     * non-open groups therefore reaches truncation — and so a `null`
     * answer — sooner than its live group count suggests. That degrades
     * CLOSED (the caller keeps its stricter gate), so it is a
     * reduced-visibility risk rather than a disclosure one, but it is a
     * real change in when the bound bites. The live counts are worth
     * knowing before this ships widely:
     *
     *     SELECT p.post_status, COUNT(*) AS groups
     *       FROM wp_posts p
     *      WHERE p.post_type = 'peepso-group'
     *        AND EXISTS (SELECT 1 FROM wp_postmeta pm
     *                     WHERE pm.post_id = p.ID
     *                       AND pm.meta_key = 'peepso_group_privacy'
     *                       AND CAST(pm.meta_value AS UNSIGNED) IN (1, 2))
     *      GROUP BY p.post_status;
     *
     * @return list<int>|null null = cannot answer; caller must fail closed
     */
    public static function getNonOpenGroupIdsStrict(int $limit = self::NONOPEN_DEFAULT_LIMIT): ?array
    {
        if ($limit <= 0) {
            return null;
        }
        if ($limit > self::NONOPEN_DEFAULT_LIMIT) {
            $limit = self::NONOPEN_DEFAULT_LIMIT;
        }

        // Only a PROVEN-complete list is ever stored. (The `:strict` suffix
        // keeps the key the deployed cache already uses.)
        $genKey = self::nonOpenCacheKey($limit) . ':strict';
        $cached = wp_cache_get($genKey, self::NONOPEN_CACHE_GROUP);
        if (is_array($cached)) {
            /** @var list<int> $cached */
            return $cached;
        }

        global $wpdb;

        // ⚠ NO post_status FILTER HERE.
        //
        // A deny list must be OVER-inclusive to be safe, and filtering to
        // `publish` made it under-inclusive: a TRASHED or draft secret
        // group dropped out of the set, so its posts stopped being
        // excluded. A trashed secret group is still secret — nothing about
        // moving the group row to the trash makes its content public — and
        // a caller that replaces its visibility gate with this list would
        // then show those posts to anyone. Under-inclusion is the LEAKING
        // direction, so this query asks only "is this group non-open",
        // whatever state the group row is in.
        //
        // ⚠ PeepSoGroupPrivacy::nonOpenSql(), never a JOIN or a string
        // compare of meta_value: it reads the value with the same CAST as
        // every other privacy read, and returns each group ONCE however many
        // privacy rows it carries, so the `$limit + 1` truncation detector
        // counts groups, not privacy rows. The EXISTS probe uses the
        // postmeta (post_id, meta_key) index per group.
        $params = [
            self::POST_TYPE,
            // limit + 1: the extra row is the truncation detector.
            $limit + 1,
        ];

        $sql = "SELECT p.ID
                  FROM {$wpdb->posts} p
                 WHERE p.post_type   = %s
                   AND " . PeepSoGroupPrivacy::nonOpenSql('p.ID') . "
                 ORDER BY p.ID DESC
                 LIMIT %d";

        // Failure detection, and why it is NOT a null check. `get_col()`
        // ALWAYS returns an array (wpdb builds `[]` and fills it only from
        // `last_result`), so an errored query would arrive as `[]`, which
        // this method documents as "the query ran and nothing is non-open".
        // `query()` is the documented signal (`false` on error), checked
        // rather than `last_error` alone because it can return false BEFORE
        // `flush()` (`! $this->ready`, an emptied query); `last_error` is
        // still consulted as a second signal.
        $prepared = $wpdb->prepare($sql, ...$params);
        $queryOk  = $wpdb->query($prepared);
        if ($queryOk === false || (string) $wpdb->last_error !== '') {
            return null;
        }

        /** @var list<numeric-string> $rows */
        $rows = $wpdb->get_col(null);

        // The detector row came back, so rows were dropped. Refuse, and
        // cache nothing — an incomplete list must never be served again.
        if (count($rows) > $limit) {
            return null;
        }

        $ids = [];
        foreach ($rows as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        wp_cache_set($genKey, $ids, self::NONOPEN_CACHE_GROUP, self::NONOPEN_CACHE_TTL);

        return $ids;
    }

    /**
     * Bust the {@see getNonOpenGroupIdsStrict} cache. Wired in bcc-core.php
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
     * `actives` is the count of DISTINCT authors who posted in the window
     * — "how many people are actually talking here", as opposed to how
     * many joined. It rides on this query rather than a second one: the
     * rows are already grouped and scanned, so it costs one aggregate
     * over the same index path.
     *
     * `$excludeActIds` are activity ids left out of every number: bcc-trust
     * passes the posts moderation has hidden, which stay `publish`, so the
     * counts exclude moderated posts. They drop out of `posts`, `actives`
     * and `last_at` alike. Bounded by the caller (bcc-trust's hidden list is
     * capped by its own reader); inlined as integers after intval(), a
     * constant IN list. Empty: no exclusion, exactly as before.
     *
     * ⚠ FAILS CLOSED: null when the read cannot be trusted (ProbesReads:
     * an error, or wpdb's silent early return), never an empty map that
     * would read as "no activity". Callers show no number then.
     *
     * @param int[]     $groupIds
     * @param list<int> $excludeActIds
     * @return array<int, object{posts: int, actives: int, last_at: string|null}>|null
     */
    public static function getActivityHeat(array $groupIds, int $sinceSeconds = 7 * DAY_IN_SECONDS, array $excludeActIds = []): ?array
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

        // Integers only (intval, positive, each once), so the list carries
        // no `%` into prepare().
        $exclude = [];
        foreach ($excludeActIds as $raw) {
            $actId = (int) $raw;
            if ($actId > 0) {
                $exclude[$actId] = $actId;
            }
        }
        $notExcluded = $exclude === [] ? '' : ' AND a.act_id NOT IN (' . implode(',', $exclude) . ')';

        $sql = $wpdb->prepare(
            "SELECT pm.meta_value AS group_id,
                    COUNT(*) AS posts,
                    COUNT(DISTINCT p.post_author) AS actives,
                    MAX(p.post_date_gmt) AS last_at
               FROM {$activities} a
         INNER JOIN {$wpdb->posts}    p  ON p.ID       = a.act_external_id
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id  = p.ID
                                         AND pm.meta_key = 'peepso_group_id'
              WHERE pm.meta_value     IN ({$placeholders})
                AND p.post_status     = 'publish'
                AND p.post_date_gmt   >= %s{$notExcluded}
              GROUP BY pm.meta_value
              LIMIT 500",
            ...$params
        );

        /** @var list<object{group_id: numeric-string, posts: numeric-string, actives: numeric-string, last_at: string|null}>|null $rows */
        $rows = is_string($sql) ? self::trustedRows($sql) : null;
        if ($rows === null) {
            return null;
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->group_id] = (object) [
                'posts'   => (int) $row->posts,
                'actives' => (int) $row->actives,
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
     * → everyone else (3), tiebroken by `gm_joined DESC` (newest
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
     * Clock-split note (BCC norm = UTC): `gm_joined` is a TIMESTAMP and
     * MySQL renders it in the SESSION time zone (SYSTEM = Central on
     * prod/staging), so the `joined_at` wall-time string is NOT UTC —
     * consumers that stamp it as UTC are 5–6h wrong. `gm_joined_utc`
     * (UNIX_TIMESTAMP epoch seconds) is tz-proof; consumers MUST prefer
     * it and format in PHP. `joined_at` stays for back-compat only
     * (bcc-trust switches in a paired PR; deploy order core-first).
     *
     * @return list<object{user_id: numeric-string, role: string, joined_at: string, gm_joined_utc: numeric-string}>
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
                    gm_joined      AS joined_at,
                    UNIX_TIMESTAMP(gm_joined) AS gm_joined_utc,
                    CASE
                        WHEN gm_user_status = 'member_owner'                              THEN 1
                        WHEN gm_user_status IN ('member_moderator', 'member_manager')     THEN 2
                        ELSE 3
                    END AS role_rank
               FROM {$members}
              WHERE gm_group_id = %d
                AND gm_user_status LIKE %s
              ORDER BY role_rank ASC, gm_joined DESC, gm_id DESC
              LIMIT %d OFFSET %d",
            $groupId,
            self::ACTIVE_MEMBER_STATUS,
            $limit,
            $offset
        );

        /** @var list<object{user_id: numeric-string, role: string, joined_at: string, gm_joined_utc: numeric-string, role_rank: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $out = [];
        foreach ($rows ?: [] as $row) {
            // Drop the synthetic role_rank from the wire shape — caller
            // doesn't need it; the stable contract is {user_id, role,
            // joined_at, gm_joined_utc} (prefer gm_joined_utc — see the
            // clock-split note in the method docblock).
            $out[] = (object) [
                'user_id'       => $row->user_id,
                'role'          => $row->role,
                'joined_at'     => $row->joined_at,
                'gm_joined_utc' => $row->gm_joined_utc,
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

    /** PeepSo's two pending states (F-272): a join request, an invitation. */
    public const PENDING_REQUEST = 'pending_admin';
    public const PENDING_INVITE  = 'pending_user';

    /**
     * One group's open join requests (`pending_admin`) or outstanding
     * invitations (`pending_user`), newest first. F-272 owner/manager lists.
     *
     * `requested_utc` is UNIX_TIMESTAMP(gm_joined): the row's creation time
     * (gm_joined has no ON UPDATE), i.e. when the request or invitation was
     * made — epoch seconds, tz-proof (see listGroupMembers' clock note).
     * `invited_by_id` is null for a request.
     *
     * Bounded (§4): LIMIT ≤ 100, gm_id tiebreak for stable pages. Uncached
     * for the same reason as listGroupMembers (PeepSo owns the writes).
     *
     * Fail-closed: null when the read did not run (a failure must not
     * read as "no requests").
     *
     * @param self::PENDING_* $status
     * @return list<object{user_id: numeric-string, invited_by_id: numeric-string|null, requested_utc: numeric-string}>|null
     */
    public static function listPendingMembers(int $groupId, string $status, int $offset, int $limit): ?array
    {
        if ($groupId <= 0 || $limit <= 0
            || ($status !== self::PENDING_REQUEST && $status !== self::PENDING_INVITE)) {
            return [];
        }
        $offset = max(0, $offset);
        $limit  = min(100, $limit);

        global $wpdb;
        $members = self::membersTable();

        $ok = $wpdb->query($wpdb->prepare(
            "SELECT gm_user_id       AS user_id,
                    gm_invited_by_id AS invited_by_id,
                    UNIX_TIMESTAMP(gm_joined) AS requested_utc
               FROM {$members}
              WHERE gm_group_id    = %d
                AND gm_user_status = %s
              ORDER BY gm_joined DESC, gm_id DESC
              LIMIT %d OFFSET %d",
            $groupId,
            $status,
            $limit,
            $offset
        ));
        if ($ok === false || (string) $wpdb->last_error !== '') {
            return null;
        }

        /** @var list<object{user_id: numeric-string, invited_by_id: numeric-string|null, requested_utc: numeric-string}> */
        return is_array($wpdb->last_result) ? array_values($wpdb->last_result) : [];
    }

    /**
     * How many open requests or invitations one group has (the owner's
     * pending badge, F-272). Same filter as listPendingMembers().
     *
     * Fail-closed: null when the read did not run.
     *
     * @param self::PENDING_* $status
     */
    public static function countPendingMembers(int $groupId, string $status): ?int
    {
        if ($groupId <= 0 || ($status !== self::PENDING_REQUEST && $status !== self::PENDING_INVITE)) {
            return 0;
        }

        global $wpdb;
        $members = self::membersTable();

        $ok = $wpdb->query($wpdb->prepare(
            "SELECT COUNT(*) AS n
               FROM {$members}
              WHERE gm_group_id    = %d
                AND gm_user_status = %s",
            $groupId,
            $status
        ));
        if ($ok === false || (string) $wpdb->last_error !== '' || !isset($wpdb->last_result[0]->n)) {
            return null;
        }
        return (int) $wpdb->last_result[0]->n;
    }

    /**
     * The groups that invited this user and are waiting on an answer,
     * newest first (F-272 "my invites" — the only way a secret group's
     * invitee finds it). Bounded: LIMIT ≤ 100, gm_id tiebreak.
     *
     * Fail-closed: null when the read did not run.
     *
     * @return list<object{group_id: numeric-string, invited_by_id: numeric-string|null, invited_utc: numeric-string}>|null
     */
    public static function listUserPendingInvites(int $userId, int $offset, int $limit): ?array
    {
        if ($userId <= 0 || $limit <= 0) {
            return [];
        }
        $offset = max(0, $offset);
        $limit  = min(100, $limit);

        global $wpdb;
        $members = self::membersTable();

        $ok = $wpdb->query($wpdb->prepare(
            "SELECT gm_group_id      AS group_id,
                    gm_invited_by_id AS invited_by_id,
                    UNIX_TIMESTAMP(gm_joined) AS invited_utc
               FROM {$members}
              WHERE gm_user_id     = %d
                AND gm_user_status = %s
              ORDER BY gm_joined DESC, gm_id DESC
              LIMIT %d OFFSET %d",
            $userId,
            self::PENDING_INVITE,
            $limit,
            $offset
        ));
        if ($ok === false || (string) $wpdb->last_error !== '') {
            return null;
        }

        /** @var list<object{group_id: numeric-string, invited_by_id: numeric-string|null, invited_utc: numeric-string}> */
        return is_array($wpdb->last_result) ? array_values($wpdb->last_result) : [];
    }

    /**
     * Total count for the same filter set as listHalls(). Drives the
     * pagination.total / pagination.total_pages fields. Optional chain
     * filter via the `_bcc_chain_tag` numeric-chain-id post-meta, and the
     * same optional name and Hall-kind filters as listHalls().
     *
     * @param self::HALL_KIND_*|null $hallKind
     */
    public static function countHalls(?int $chainId, string $nameQuery = '', ?string $hallKind = null): int
    {
        global $wpdb;
        [$nameSql, $nameArgs] = self::nameQueryClause($nameQuery, 'p.post_title');
        [$kindSql, $kindArgs] = self::hallKindClause($hallKind, 'p.ID');

        $chainJoin = $chainId !== null
            ? " INNER JOIN {$wpdb->postmeta} pm_chain ON pm_chain.post_id = p.ID
                                                     AND pm_chain.meta_key = %s
                                                     AND pm_chain.meta_value = %d"
            : '';

        $sql = "SELECT COUNT(*)
                  FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = p.ID
                                                AND pm_kind.meta_key = %s
                                                AND pm_kind.meta_value = %s"
             . $chainJoin
             . " WHERE p.post_type = %s AND p.post_status = %s{$nameSql}{$kindSql}";

        $params = [self::META_KIND, self::KIND_HALL];
        if ($chainId !== null) {
            $params[] = self::META_CHAIN_TAG;
            $params[] = $chainId;
        }
        $params[] = self::POST_TYPE;
        $params[] = self::POST_STATUS;
        array_push($params, ...$nameArgs, ...$kindArgs);

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    // -----------------------------------------------------------------
    // TRANSITIONAL Local→Hall compat wrappers (expand-and-contract).
    //
    // These six deprecated methods exist SOLELY to make the production
    // Local→Hall cutover deploy-order-independent. bcc-core and bcc-trust
    // deploy independently; the Hall rename removed the old Local method
    // names from this repository in lockstep with bcc-trust switching to
    // the new Hall names. Deploying new-core against still-running
    // old-trust would therefore fatal on old-trust's 11 call sites.
    //
    // Re-adding these names as deprecated delegates to the Hall methods
    // lets a single compat-core satisfy BOTH old-trust (calls Local names)
    // and new-trust (calls Hall names) simultaneously — eliminating the
    // window instead of merely shrinking it.
    //
    // They register NOTHING: no REST route, no hook, no meta key. Core
    // has never had a /locals route (that was always trust-side). These
    // are pure internal repository reads and cannot resurrect it.
    //
    // Purely additive. Delete this entire block in the follow-up CONTRACT
    // release once every plugin is confirmed on the Hall method names
    // (`git grep` of the old names across all plugins = clean).
    // -----------------------------------------------------------------

    /**
     * @deprecated Transitional shim for the Local→Hall cutover
     *             (expand-and-contract). Delegates to
     *             {@see PeepSoGroupRepository::findHallBySlug}. Remove
     *             once all callers are on Hall methods. Do NOT reintroduce
     *             Local wording or emit new bcc_local_post.
     *
     * Identical signature + return shape to the Hall method — a pure
     * pass-through.
     *
     * @return object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null
     */
    public static function findOneBySlug(string $slug): ?object
    {
        return self::findHallBySlug($slug);
    }

    /**
     * @deprecated Transitional shim for the Local→Hall cutover
     *             (expand-and-contract). Delegates to
     *             {@see PeepSoGroupRepository::findHallById}. Remove once
     *             all callers are on Hall methods. Do NOT reintroduce Local
     *             wording or emit new bcc_local_post.
     *
     * Identical signature + return shape to the Hall method — a pure
     * pass-through.
     *
     * @return object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}|null
     */
    public static function findOneById(int $groupId): ?object
    {
        return self::findHallById($groupId);
    }

    /**
     * @deprecated Transitional shim for the Local→Hall cutover
     *             (expand-and-contract). Delegates to
     *             {@see PeepSoGroupRepository::getPrimaryHallForUsers}.
     *             Remove once all callers are on Hall methods. Do NOT
     *             reintroduce Local wording or emit new bcc_local_post.
     *
     * Identical signature + return shape to the Hall method — a pure
     * pass-through. The Hall reader keys on the `bcc_primary_hall_group_id`
     * usermeta; this shim does NOT read or write the retired
     * `bcc_primary_local_group_id` literal anywhere.
     *
     * @param int[] $userIds
     * @return array<int, object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}>
     */
    public static function getPrimaryLocalForUsers(array $userIds): array
    {
        return self::getPrimaryHallForUsers($userIds);
    }

    /**
     * @deprecated Transitional shim for the Local→Hall cutover
     *             (expand-and-contract). Delegates to
     *             {@see PeepSoGroupRepository::findUsersByPrimaryHall}.
     *             Remove once all callers are on Hall methods. Do NOT
     *             reintroduce Local wording or emit new bcc_local_post.
     *
     * Identical signature + return shape to the Hall method — a pure
     * pass-through.
     *
     * @return list<int>
     */
    public static function findUsersByPrimaryLocal(int $groupId, int $limit = 1000): array
    {
        return self::findUsersByPrimaryHall($groupId, $limit);
    }

    /**
     * @deprecated Transitional shim for the Local→Hall cutover
     *             (expand-and-contract). Delegates to
     *             {@see PeepSoGroupRepository::listHalls}. Remove once all
     *             callers are on Hall methods. Do NOT reintroduce Local
     *             wording or emit new bcc_local_post.
     *
     * Keeps the OLD `?string $chain` signature so still-running old-trust
     * binds against it, and delegates with a null chain filter.
     *
     * Why `$chain` is discarded, not passed through: the old `$chain` was a
     * string slug matched against the post title; `listHalls` takes an INT
     * chain id (`_bcc_chain_tag` meta). Resolving slug→id needs bcc-trust's
     * ChainRepository, and bcc-core cannot depend on bcc-trust (the
     * dependency runs trust→core, not the reverse), so a core-side wrapper
     * physically cannot bridge the filter to `listHalls`.
     *
     * Why discarding it is nonetheless safe for the only window this shim is
     * live in — new-Core running against old-Trust: the Hall provisioner
     * (`bcc_hall_provision` cron/admin) is new-Trust-only code, so it cannot
     * have run yet ⇒ ZERO Halls exist during the entire window, and there
     * have never been any `Local %`-titled groups. `listHalls(null)` and
     * `listHalls(anyChainId)` therefore both return the empty list — the
     * chain filter is inert on an empty set — matching what the retired
     * `listLocals($chain)` returned. Every old caller already null-guards.
     *
     * @return list<object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}>
     */
    public static function listLocals(?string $chain, int $offset, int $limit): array
    {
        return self::listHalls(null, $offset, $limit);
    }

    /**
     * @deprecated Transitional shim for the Local→Hall cutover
     *             (expand-and-contract). Delegates to
     *             {@see PeepSoGroupRepository::countHalls}. Remove once all
     *             callers are on Hall methods. Do NOT reintroduce Local
     *             wording or emit new bcc_local_post.
     *
     * Keeps the OLD `?string $chain` signature so still-running old-trust
     * binds against it, and delegates with a null chain filter — same
     * reasoning as {@see PeepSoGroupRepository::listLocals}: core cannot
     * resolve the slug→id filter (ChainRepository is trust-side), and the
     * new-Trust-only Hall provisioner cannot have run during a new-Core +
     * old-Trust window ⇒ ZERO Halls (and never any `Local %`-titled groups),
     * so both count paths return 0 regardless of `$chain`. The slug is
     * intentionally ignored.
     */
    public static function countLocals(?string $chain): int
    {
        return self::countHalls(null);
    }
}
