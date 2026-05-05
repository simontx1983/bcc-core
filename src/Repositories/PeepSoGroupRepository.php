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
     * Bulk-fetch group post info (id, slug, title, member_count) for a
     * set of group_ids. Used by the User view-model's `locals` field
     * to enrich a user's memberships with display info — single query,
     * no N+1.
     *
     * @param int[] $groupIds
     * @return array<int, object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}>
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
            "SELECT p.ID AS id, p.post_name, p.post_title,
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

        /** @var list<object{id: numeric-string, post_name: string, post_title: string, member_count: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($sql);

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->id] = $row;
        }
        return $map;
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
     * Activity heat metrics (post count + last activity timestamp) for
     * a set of groups, restricted to the last $sinceSeconds window.
     *
     * Joins peepso_activities → wp_posts (act_id = posts.ID) and filters
     * to act_module_id = 8 (PeepSoGroups::MODULE_ID, group activity rows).
     * Only `publish` posts count toward heat; pending / draft / trashed
     * are excluded so reported / removed posts don't inflate counts.
     *
     * Used by the holder-groups suggestion surface (PR 2) and the
     * groups-discovery sort. Groups with zero posts in the window are
     * absent from the map — caller treats absence as cold/zero.
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
        $placeholders = implode(',', array_fill(0, count($groupIds), '%d'));
        $cutoff       = gmdate('Y-m-d H:i:s', time() - max(60, $sinceSeconds));

        $args = array_merge($groupIds, [$cutoff]);
        $sql  = $wpdb->prepare(
            "SELECT a.act_external_id AS group_id,
                    COUNT(*) AS posts,
                    MAX(p.post_date_gmt) AS last_at
               FROM {$activities} a
         INNER JOIN {$wpdb->posts} p ON p.ID = a.act_id
              WHERE a.act_module_id   = 8
                AND a.act_external_id IN ({$placeholders})
                AND p.post_status     = 'publish'
                AND p.post_date_gmt   >= %s
              GROUP BY a.act_external_id
              LIMIT 500",
            ...$args
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
