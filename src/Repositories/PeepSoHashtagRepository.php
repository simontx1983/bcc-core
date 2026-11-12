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

    /** How many of the most-used tags are recounted publicly (F-168). */
    public const PUBLIC_CANDIDATES = 50;

    /** Transient holding the publicly recounted ranking (the full MAX_LIMIT slice). */
    public const PUBLIC_CACHE_KEY = 'bcc_core_trending_public_v1';

    /** Seconds the public ranking is cached. */
    public const PUBLIC_CACHE_TTL = 600;

    /**
     * Trending hashtags counted over posts ANYONE can see (F-168).
     *
     * `ht_count` is PeepSo's own counter, and PeepSo counts every published
     * `peepso-post`: closed and secret group posts, members-only and private
     * posts, posts by authors whose profile is private. Ranking on it tells a
     * logged-out visitor a tag is hot when every post behind it is hidden
     * from them, and lists a hidden-only tag at all. `getTrending()` is kept
     * unchanged; the public surface uses this instead:
     *
     *   1. candidates = the PUBLIC_CANDIDATES tags with the highest
     *      `ht_count` (bounded);
     *   2. every candidate is recounted in ONE aggregate pass over the posts
     *      an anonymous viewer may see, with PeepSo's own match predicate
     *      (`PeepSo::hashtags_query_where`: content wrapped in spaces; tab,
     *      CR, LF and NBSP read as spaces; `LIKE '% #tag %'`);
     *   3. zero counts are dropped, the rest ordered by public count (ties
     *      keep PeepSo's order) and cut to `$limit`.
     *
     * "Anyone can see" is the rule an anonymous reader of the global feed
     * gets:
     *   - a published `peepso-post`;
     *   - not group-tagged, or group-tagged AND marked `public_all`
     *     (PeepSoActivityRepository's global-feed gate);
     *   - effective access 10: the STRICTER of the post's `act_access`
     *     (`<= 0` is public, as BCC's writer and PeepSo both treat it) and
     *     the author's `usr_profile_acc` (F-02), across EVERY activity row
     *     backing the post. One non-public row hides the post.
     *
     * Cached for PUBLIC_CACHE_TTL seconds as one ranking; `$limit` only
     * slices it. A failed read returns [] and is NOT cached: trending fails
     * closed (an empty rail) and recovers on the next request.
     *
     * @return list<object{ht_name: string, ht_count: int}>
     */
    public static function getTrendingPubliclyVisible(int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $cached = get_transient(self::PUBLIC_CACHE_KEY);
        if (is_array($cached)) {
            $ranking = self::rankingFromCache($cached);
            if ($ranking !== null) {
                return array_slice($ranking, 0, $limit);
            }
        }

        $ranking = self::computePublicRanking();
        if ($ranking === null) {
            return [];
        }
        $toCache = [];
        foreach ($ranking as $r) {
            $toCache[] = [$r->ht_name, $r->ht_count];
        }
        set_transient(self::PUBLIC_CACHE_KEY, $toCache, self::PUBLIC_CACHE_TTL);

        return array_slice($ranking, 0, $limit);
    }

    /**
     * @param array<mixed> $cached
     * @return list<object{ht_name: string, ht_count: int}>|null null = not a ranking this version wrote
     */
    private static function rankingFromCache(array $cached): ?array
    {
        $out = [];
        foreach ($cached as $entry) {
            if (!is_array($entry) || !isset($entry[0], $entry[1]) || !is_string($entry[0]) || !is_int($entry[1])) {
                return null;
            }
            $out[] = (object) ['ht_name' => $entry[0], 'ht_count' => $entry[1]];
        }
        return $out;
    }

    /**
     * @return list<object{ht_name: string, ht_count: int}>|null null = a read failed
     */
    private static function computePublicRanking(): ?array
    {
        global $wpdb;

        $candidates = self::trustedRows($wpdb->prepare(
            'SELECT ht_name
               FROM ' . self::table() . '
              WHERE ht_count > 0
              ORDER BY ht_count DESC, ht_id DESC
              LIMIT %d',
            self::PUBLIC_CANDIDATES
        ));
        if ($candidates === null) {
            return null;
        }

        $names = [];
        foreach ($candidates as $row) {
            $name = isset($row->ht_name) ? (string) $row->ht_name : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names === []) {
            return [];
        }

        // PeepSo's normalisation (PeepSo::hashtags_query_where), on p.post_content.
        $content = "CONCAT(' ', REPLACE(REPLACE(REPLACE(REPLACE(p.post_content, CHAR(9), ' '), CHAR(13), ' '), CHAR(10), ' '), CHAR(194, 160 USING utf8mb4), ' '), ' ')";

        $sums   = [];
        $params = [];
        foreach ($names as $i => $name) {
            $sums[]   = "COALESCE(SUM({$content} LIKE %s), 0) AS c{$i}";
            $params[] = '% #' . $wpdb->esc_like($name) . ' %';
        }

        $acts  = $wpdb->prefix . 'peepso_activities';
        $level = static fn (string $a): string => "GREATEST(IF({$a}.act_access IS NULL OR {$a}.act_access <= 0, 10, {$a}.act_access), COALESCE(pu.usr_profile_acc, 10))";

        $sql = 'SELECT ' . implode(', ', $sums) . '
                  FROM ' . $wpdb->posts . ' p
                  LEFT JOIN ' . $wpdb->prefix . 'peepso_users pu
                    ON pu.usr_id = p.post_author
                  LEFT JOIN ' . $wpdb->postmeta . ' gx_pm
                    ON gx_pm.post_id  = p.ID
                   AND gx_pm.meta_key = \'peepso_group_id\'
                  LEFT JOIN ' . $wpdb->postmeta . ' vis_pm
                    ON vis_pm.post_id  = p.ID
                   AND vis_pm.meta_key = \'_bcc_post_visibility\'
                 WHERE p.post_type   = \'peepso-post\'
                   AND p.post_status = \'publish\'
                   AND p.post_content LIKE \'%%#%%\'
                   AND (gx_pm.meta_value IS NULL OR vis_pm.meta_value = \'public_all\')
                   AND EXISTS (SELECT 1 FROM ' . $acts . ' a_pub
                                WHERE a_pub.act_external_id = p.ID
                                  AND ' . $level('a_pub') . ' = 10)
                   AND NOT EXISTS (SELECT 1 FROM ' . $acts . ' a_hid
                                    WHERE a_hid.act_external_id = p.ID
                                      AND ' . $level('a_hid') . ' <> 10)';

        $rows = self::trustedRows($wpdb->prepare($sql, ...$params));
        if ($rows === null || !isset($rows[0])) {
            return null;
        }
        $totals = (array) $rows[0];

        $ranking = [];
        foreach ($names as $i => $name) {
            $count = (int) ($totals['c' . $i] ?? 0);
            if ($count > 0) {
                $ranking[] = ['name' => $name, 'count' => $count, 'order' => $i];
            }
        }
        usort($ranking, static fn (array $a, array $b): int => [$b['count'], $a['order']] <=> [$a['count'], $b['order']]);

        $out = [];
        foreach (array_slice($ranking, 0, self::MAX_LIMIT) as $r) {
            $out[] = (object) ['ht_name' => $r['name'], 'ht_count' => $r['count']];
        }
        return $out;
    }

    /**
     * Run a SELECT and return its rows only when they can be TRUSTED.
     *
     * `query()` is called explicitly (not via `get_results($sql)`) because
     * wpdb has failure paths that return false without setting `last_error`
     * and before `flush()`, leaving the PREVIOUS query's rows in
     * `last_result` (see PeepSoBlockRepository). Both signals are read.
     *
     * @return list<object>|null null = the read failed
     */
    private static function trustedRows(string $sql): ?array
    {
        global $wpdb;

        self::clearLastError();
        $ok = $wpdb->query($sql);
        if ($ok === false || self::lastReadFailed()) {
            return null;
        }
        $rows = $wpdb->last_result;
        return is_array($rows) ? array_values($rows) : [];
    }

    /** A named helper so PHPStan does not narrow `last_error` to '' across the query. */
    private static function clearLastError(): void
    {
        global $wpdb;
        $wpdb->last_error = '';
    }

    private static function lastReadFailed(): bool
    {
        global $wpdb;
        return (string) $wpdb->last_error !== '';
    }
}
