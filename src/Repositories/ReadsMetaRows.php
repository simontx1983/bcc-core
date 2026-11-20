<?php
/**
 * ReadsMetaRows — the one bounded, probed read of a WordPress meta table,
 * shared by {@see UserMetaRepository} (wp_usermeta) and
 * {@see PostMetaRepository} (wp_postmeta), so the two cannot drift.
 *
 * ⚠ The using class must also `use ProbesReads`: the rows come from
 * `trustedRows()` (clearLastError, `query() === false`, last_error), never
 * get_results()/get_col() plus last_error, which can hand back the PREVIOUS
 * query's rows when query() returns before flush().
 *
 * The read answers null when it cannot be trusted:
 *   - a chunk's query fails, or its rows reach the chunk's LIMIT (ORDER BY
 *     the meta id cuts the NEWEST rows, which can include some pair's only
 *     row);
 *   - a row names an object id the chunk did not ask for.
 * A row whose key is only a case variant of a requested key (a `_ci`
 * collation match) is skipped, as WordPress's exact-key meta read skips it.
 *
 * @package BCC\Core\Repositories
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

trait ReadsMetaRows
{
    /**
     * The read, chunked. Every requested id maps every key, [] for no row,
     * values lowest meta id first. Null when the read cannot be trusted.
     *
     * @param string       $table        the meta table, e.g. `$wpdb->postmeta`
     * @param string       $idColumn     `user_id` / `post_id`
     * @param string       $metaIdColumn `umeta_id` / `meta_id`
     * @param list<int>    $ids
     * @param list<string> $keys
     * @param positive-int $chunkSize      ids per query
     * @param positive-int $maxRowsPerPair rows allowed per (id, key) on average
     * @return array<int, array<string, list<string>>>|null
     */
    private static function readMetaRows(
        string $table,
        string $idColumn,
        string $metaIdColumn,
        array $ids,
        array $keys,
        int $chunkSize,
        int $maxRowsPerPair
    ): ?array {
        global $wpdb;

        $out = [];
        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $limit = count($chunk) * count($keys) * $maxRowsPerPair;
            $sql   = $wpdb->prepare(
                "SELECT {$idColumn}, meta_key, meta_value FROM {$table}
                  WHERE {$idColumn} IN (" . implode(', ', array_fill(0, count($chunk), '%d')) . ")
                    AND meta_key IN (" . implode(', ', array_fill(0, count($keys), '%s')) . ")
                  ORDER BY {$metaIdColumn} ASC
                  LIMIT %d",
                ...[...$chunk, ...$keys, $limit]
            );
            if (!is_string($sql) || $sql === '') {
                return null;
            }

            $rows = self::trustedRows($sql);
            if ($rows === null || count($rows) >= $limit) {
                return null;
            }

            $asked = [];
            foreach ($chunk as $id) {
                $asked[$id]  = true;
                $out[$id]    = array_fill_keys($keys, []);
            }
            foreach ($rows as $row) {
                $rawId = $row->{$idColumn} ?? null;
                $id    = is_numeric($rawId) ? (int) $rawId : 0;
                $key   = isset($row->meta_key) ? (string) $row->meta_key : '';
                if (!isset($asked[$id])) {
                    // An object this query did not ask for: the rows are not ours.
                    return null;
                }
                if (!array_key_exists($key, $out[$id])) {
                    // A case variant matched by a _ci collation. WordPress reads
                    // meta keys exactly, so this skips it as the meta API does.
                    continue;
                }
                $out[$id][$key][] = isset($row->meta_value) ? (string) $row->meta_value : '';
            }
        }

        return $out;
    }

    /**
     * The public shape from a full map: only pairs with rows.
     *
     * @param array<int, array<string, list<string>>> $source
     * @param list<int>    $ids
     * @param list<string> $keys
     * @return array<int, array<string, list<string>>>
     */
    private static function present(array $source, array $ids, array $keys): array
    {
        $out = [];
        foreach ($ids as $id) {
            foreach ($keys as $key) {
                $values = $source[$id][$key] ?? [];
                if ($values !== []) {
                    $out[$id][$key] = $values;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<int> $objectIds
     * @return list<int>
     */
    private static function cleanIds(array $objectIds): array
    {
        $ids = [];
        foreach ($objectIds as $id) {
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function cleanKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if ($key !== '') {
                $out[$key] = $key;
            }
        }

        return array_values($out);
    }
}
