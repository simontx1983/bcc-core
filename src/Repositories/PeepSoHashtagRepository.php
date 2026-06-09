<?php
/**
 * PeepSoHashtagRepository — read-only access to PeepSo's hashtag table.
 *
 * Backing table: {prefix}peepso_hashtags. PeepSo owns the WRITE path
 * (it increments `ht_count` as posts carrying a hashtag are created /
 * removed via its own maintenance jobs). This repository ONLY reads —
 * we never duplicate PeepSo's counter into a parallel BCC table (§11).
 *
 * PeepSo's schema (verified 2026-06-09):
 *   - ht_id     PK
 *   - ht_name   the tag text WITHOUT the leading '#' (PeepSo stores it bare)
 *   - ht_count  number of posts PeepSo has counted for the tag
 *
 * No SELECT *. No writes. Every read is bounded by a hard LIMIT.
 *
 * @package BCC\Core\Repositories
 * @since V1 (2026-06, trending-hashtags surface)
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoHashtagRepository
{
    private const TABLE_SUFFIX = 'peepso_hashtags';

    /**
     * Explicit SELECT list — only the two columns the trending surface
     * needs. No SELECT * (§2).
     */
    private const COLUMNS = 'ht_name, ht_count';

    /** Hard ceiling on the trending slice — mirrors the endpoint's max. */
    private const MAX_LIMIT = 20;

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Most-used hashtags, ordered by usage descending. Used by
     * GET /bcc/v1/hashtags/trending.
     *
     * `$limit` is defensively clamped to [1, MAX_LIMIT] here regardless
     * of what the caller passes — the repository is the last line of
     * defense for the bound (§4).
     *
     * Only tags with `ht_count > 0` are returned: PeepSo can leave a
     * zero-count row behind after a tag's posts are all deleted, and a
     * "trending" tag with zero uses is meaningless.
     *
     * @return list<object{ht_name: string, ht_count: int|numeric-string}>
     * @phpstan-return list<object{ht_name: string, ht_count: int|numeric-string}>
     */
    public static function getTrending(int $limit): array
    {
        global $wpdb;

        $limit = max(1, min(self::MAX_LIMIT, $limit));

        /** @phpstan-var list<object{ht_name: string, ht_count: int|numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM ' . self::table() . '
              WHERE ht_count > 0
              ORDER BY ht_count DESC, ht_id DESC
              LIMIT %d',
            $limit
        ));

        return $rows ?: [];
    }
}
