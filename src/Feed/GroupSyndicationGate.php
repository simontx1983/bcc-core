<?php
/**
 * GroupSyndicationGate — may a group-tagged post appear OUTSIDE its
 * group (global / hot / tag feeds, the public permalink, trending)?
 *
 * The ONE rule, shared by every global surface so they cannot drift
 * (PeepSoActivityRepository's global gate, ActivityFeedService's
 * permalink predicate, and the trending count).
 *
 * A group-tagged post syndicates only when it carries
 * `_bcc_post_visibility = 'public_all'` (the existing gate, applied by
 * the callers) AND this rule allows it:
 *
 *   - its group is open now                                  → allowed
 *   - its group is non-open now, but the post was made public_all while
 *     the group was ALREADY non-open (a deliberate syndication;
 *     Gate 12, "public_all wins")                             → allowed
 *   - its group is non-open now and the post was made while the group
 *     was open, OR there is no record (posts written before the record
 *     existed)                                                 → DROPPED
 *
 * F-23, user decision 2026-11-11 (option B). A post in several groups
 * is dropped if ANY of them drops it.
 *
 * The record is `_bcc_post_group_privacy_at_post`: the group's
 * `peepso_group_privacy` ('0' open, '1' closed, '2' secret) at the moment
 * the visibility was stamped, written by PeepSoStatusWriter::
 * attachToGroup(), the single writer of `_bcc_post_visibility`.
 *
 * FAIL CLOSED: when the non-open group set can't be read (null: query
 * error or over the cap), only posts recorded as made while non-open
 * syndicate. Those are syndicable whatever the group's state is now.
 *
 * @package BCC\Core\Feed
 */

declare(strict_types=1);

namespace BCC\Core\Feed;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupSyndicationGate
{
    /** Post meta: the group's privacy when the post's visibility was stamped. */
    public const META_PRIVACY_AT_POST = '_bcc_post_group_privacy_at_post';

    /** `peepso_group_privacy` values that mean non-open (closed, secret). */
    public const NON_OPEN_PRIVACY = ['1', '2'];

    /**
     * SQL predicate for a query whose post table alias is $postAlias.
     * AND it into the WHERE. Uses its own aliases (gsg_*), no joins, so
     * it can't multiply rows.
     *
     * @param list<int>|null $nonOpenGroupIds from PeepSoGroupRepository::getNonOpenGroupIdsStrict(); null = unknown → fail closed
     * @return array{where: string, params: list<string>}
     */
    public static function sqlPredicate(string $postmetaTable, ?array $nonOpenGroupIds, string $postAlias = 'p'): array
    {
        $madeWhileNonOpen = 'EXISTS (SELECT 1 FROM ' . $postmetaTable . ' gsg_at'
            . ' WHERE gsg_at.post_id = ' . $postAlias . '.ID'
            . " AND gsg_at.meta_key = '" . self::META_PRIVACY_AT_POST . "'"
            . " AND gsg_at.meta_value IN ('" . implode("','", self::NON_OPEN_PRIVACY) . "'))";

        if ($nonOpenGroupIds === null) {
            // Unknown: any group-tagged post must prove it was a deliberate
            // non-open syndication.
            $inAnyGroup = 'EXISTS (SELECT 1 FROM ' . $postmetaTable . ' gsg_g'
                . ' WHERE gsg_g.post_id = ' . $postAlias . '.ID'
                . " AND gsg_g.meta_key = 'peepso_group_id')";
            return ['where' => '(NOT ' . $inAnyGroup . ' OR ' . $madeWhileNonOpen . ')', 'params' => []];
        }

        $ids = self::cleanIds($nonOpenGroupIds);
        if ($ids === []) {
            return ['where' => '', 'params' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $inNonOpen    = 'EXISTS (SELECT 1 FROM ' . $postmetaTable . ' gsg_g'
            . ' WHERE gsg_g.post_id = ' . $postAlias . '.ID'
            . " AND gsg_g.meta_key = 'peepso_group_id'"
            . ' AND gsg_g.meta_value IN (' . $placeholders . '))';

        return [
            'where'  => '(NOT ' . $inNonOpen . ' OR ' . $madeWhileNonOpen . ')',
            'params' => array_map(static fn(int $id): string => (string) $id, $ids),
        ];
    }

    /**
     * The same rule for one post, in PHP (the permalink path).
     *
     * @param list<int>      $postGroupIds    the post's peepso_group_id values
     * @param mixed          $privacyAtPost   the post's META_PRIVACY_AT_POST value
     * @param list<int>|null $nonOpenGroupIds null = unknown → fail closed
     */
    public static function allows(array $postGroupIds, mixed $privacyAtPost, ?array $nonOpenGroupIds): bool
    {
        $groups = self::cleanIds($postGroupIds);
        if ($groups === []) {
            return true;
        }
        if (self::madeWhileNonOpen($privacyAtPost)) {
            return true;
        }
        if ($nonOpenGroupIds === null) {
            return false;
        }
        return array_intersect($groups, self::cleanIds($nonOpenGroupIds)) === [];
    }

    /** True when the record says the group was closed or secret at post time. */
    public static function madeWhileNonOpen(mixed $privacyAtPost): bool
    {
        return is_scalar($privacyAtPost)
            && in_array(trim((string) $privacyAtPost), self::NON_OPEN_PRIVACY, true);
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $n = is_numeric($id) ? (int) $id : 0;
            if ($n > 0) {
                $out[$n] = true;
            }
        }
        return array_keys($out);
    }
}
