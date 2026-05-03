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
