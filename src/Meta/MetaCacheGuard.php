<?php
/**
 * MetaCacheGuard — a failed meta read must not be CACHED (F-459).
 *
 * ── The defect in WordPress ───────────────────────────────────────────
 *
 * `update_meta_cache()` (wp-includes/meta.php, WP 7.0.2) runs one SELECT for
 * every uncached object id and then caches the result. When that SELECT
 * FAILS it does not notice: `$meta_list` comes back as `array()` — `flush()`
 * has already reset `last_result` — which is indistinguishable from "these
 * objects have no meta". It then stores `array()` for EVERY requested id via
 * `wp_cache_add_multiple()`.
 *
 * So one failed query makes `get_post_meta()` answer "" for every id in that
 * batch, AND caches that answer. With a persistent object cache (production
 * runs one) `post_meta` has no expiry, so the poison survives until that
 * object's meta is written or the cache is flushed. Every gate that treats
 * absent meta as "allowed" then fails OPEN, for everyone, indefinitely.
 *
 * ⚠ This is a containment fix, not a closure. A failed read still answers
 * "" for the request it happened in, because `get_metadata_raw()` turns a
 * short-circuited `update_meta_cache()` into `null` and then into "". What
 * this removes is the PERSISTENCE. Closing the residual means teaching
 * individual gates to refuse — F-460 / F-461, consuming
 * {@see MetaReadHealth}.
 *
 * ── Why the filter is shaped the way it is ────────────────────────────
 *
 * ⛔ The obvious implementation is a trap. `update_meta_cache()` coerces any
 * short-circuit to a bool:
 *
 *     $check = apply_filters( "update_{$meta_type}_metadata_cache", null, $object_ids );
 *     if ( null !== $check ) { return (bool) $check; }
 *
 * and `get_metadata_raw()` then does `$meta_cache[ $object_id ] ?? null`. So
 * a filter that caches the rows and returns the array — or `true` — makes
 * `null` arrive there, and `get_post_meta()` answers "" ON A HEALTHY
 * DATABASE, for every object's first read in a request. That would make
 * every absent-means-allowed gate fail open ALL the time: far worse than the
 * bug being fixed.
 *
 * So on SUCCESS this returns **null**. It has already populated the cache,
 * so core's own `wp_cache_get_multiple()` finds everything, `$non_cached_ids`
 * is empty, and core returns its real array early WITHOUT running a second
 * query. One query, correct return value, no behaviour change when healthy.
 *
 * On FAILURE it caches nothing and returns **false** — the one thing the
 * filter's bool coercion is actually good for.
 *
 * ── Why not get_results() ─────────────────────────────────────────────
 *
 * ⚠⚠ Because `get_results()` cannot see one of the failure modes, and that
 * mode is fail-WRONG rather than fail-open. `wpdb::query()` returns false on
 * `! $this->ready` (a failed reconnect) and on an emptied `query` filter —
 * both BEFORE it calls `flush()`. `last_result` therefore still holds the
 * PREVIOUS query's rows, and `get_results()` discards `query()`'s return
 * value, so it hands those rows back with no `last_error` set. Caching them
 * would store one query's rows as another object's meta, persistently, for
 * every reader. Verified in class-wpdb.php, not assumed.
 *
 * {@see \BCC\Core\Repositories\ProbesReads} already solves exactly this and
 * is reused rather than reimplemented: it checks `query()`'s own return
 * (`=== false`, because a SELECT returns the ROW COUNT and `!0` is true)
 * AND `last_error`, having cleared it first so an earlier failure elsewhere
 * in the request cannot be mistaken for this one's.
 *
 * ── Equivalence ───────────────────────────────────────────────────────
 *
 * The cache contents written here are byte-for-byte what core writes for the
 * same rows, including `array()` for an id with no rows, several values under
 * one key preserved in `meta_id` order, and an empty-string value stored as
 * an empty string rather than dropped. There is a test comparing against
 * core's own `update_meta_cache()` output, because "looks right" is not
 * equivalence when this filter sits in front of every meta read on the site.
 *
 * @package BCC\Core\Meta
 */

namespace BCC\Core\Meta;

use BCC\Core\Repositories\ProbesReads;

if (!defined('ABSPATH')) {
    exit;
}

final class MetaCacheGuard
{
    use ProbesReads;

    /**
     * Wired from bcc-core.php at plugin load, before anything can read meta.
     *
     * ⚠ Post and user only. Comment and term meta are not read by any BCC
     * gate, and widening a filter that fronts every meta read on the site is
     * not free — each type added is another chance to diverge from core.
     */
    public static function register(): void
    {
        add_filter('update_post_metadata_cache', [self::class, 'primePostMeta'], 10, 2);
        add_filter('update_user_metadata_cache', [self::class, 'primeUserMeta'], 10, 2);
    }

    /**
     * @param  mixed            $check
     * @param  array<int,mixed> $objectIds
     * @return mixed null to let core continue, false to cache nothing
     */
    public static function primePostMeta($check, $objectIds)
    {
        return self::prime('post', $check, $objectIds);
    }

    /**
     * @param  mixed            $check
     * @param  array<int,mixed> $objectIds
     * @return mixed null to let core continue, false to cache nothing
     */
    public static function primeUserMeta($check, $objectIds)
    {
        return self::prime('user', $check, $objectIds);
    }

    /**
     * @param  mixed $check
     * @param  mixed $objectIds
     * @return mixed
     */
    private static function prime(string $metaType, $check, $objectIds)
    {
        // Another filter has already decided. Pass its answer through
        // untouched rather than overriding it.
        if ($check !== null) {
            return $check;
        }

        if (!is_array($objectIds) || $objectIds === []) {
            return null;
        }

        $table = _get_meta_table($metaType);
        if (!is_string($table) || $table === '') {
            return null;
        }

        $column   = sanitize_key($metaType . '_id');
        $idColumn = $metaType === 'user' ? 'umeta_id' : 'meta_id';
        $group    = $metaType . '_meta';

        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', $objectIds));

        // ⚠ Partition exactly as core does: ONLY `false` is a miss. A
        // legitimately cached EMPTY array is a HIT — treating it as a miss
        // would re-query every object that genuinely has no meta, on every
        // request.
        $nonCached = [];
        $cached    = wp_cache_get_multiple($ids, $group);
        foreach (is_array($cached) ? $cached : [] as $id => $value) {
            if (false === $value) {
                $nonCached[] = (int) $id;
            }
        }

        // Everything is already cached: core will find it and return without
        // querying. Nothing for us to do, and nothing to get wrong.
        if ($nonCached === []) {
            return null;
        }

        // Ints only (cast above), so interpolation is safe and matches the
        // query core would have run.
        $idList = implode(',', $nonCached);
        $sql    = "SELECT {$column}, meta_key, meta_value FROM {$table}"
            . " WHERE {$column} IN ({$idList}) ORDER BY {$idColumn} ASC";

        $rows = self::trustedRows($sql);

        if ($rows === null) {
            self::recordFailure($metaType, $nonCached);

            // Cache NOTHING and short-circuit. This request reads empty —
            // see the class docblock — but the next one retries instead of
            // reading poison.
            return false;
        }

        wp_cache_add_multiple(self::buildCacheData($rows, $nonCached, $column), $group);

        // Populated. Core now finds everything cached, skips its own query,
        // and returns the real array. Returning anything non-null here would
        // break that — see the class docblock.
        return null;
    }

    /**
     * Build exactly what core's `update_meta_cache()` would store.
     *
     * @param  list<object> $rows
     * @param  list<int>    $nonCached
     * @return array<int, array<string, list<mixed>>>
     */
    private static function buildCacheData(array $rows, array $nonCached, string $column): array
    {
        $cache = [];

        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->{$column})) {
                continue;
            }
            $mpid = (int) $row->{$column};
            $mkey = (string) ($row->meta_key ?? '');
            $mval = $row->meta_value ?? null;

            // Core forces both levels to arrays before appending; an
            // empty-string value is stored, not dropped.
            if (!isset($cache[$mpid]) || !is_array($cache[$mpid])) {
                $cache[$mpid] = [];
            }
            if (!isset($cache[$mpid][$mkey]) || !is_array($cache[$mpid][$mkey])) {
                $cache[$mpid][$mkey] = [];
            }
            $cache[$mpid][$mkey][] = $mval;
        }

        // Every requested id gets an entry, `array()` when it has no rows.
        // That empty entry is what stops a second read re-querying, so it is
        // part of the equivalence, not an optimisation.
        $data = [];
        foreach ($nonCached as $id) {
            $data[$id] = $cache[$id] ?? [];
        }

        return $data;
    }

    /**
     * Log it and count it, so ops can see how often this fires BEFORE
     * F-460 / F-461 start refusing requests on it.
     *
     * @param list<int> $objectIds
     */
    private static function recordFailure(string $metaType, array $objectIds): void
    {
        MetaReadHealth::markFailed($metaType, $objectIds);

        // ⚠ TWO ONE-LINE LITERAL CALLS, not a lookup. bcc-core's
        // DegradationEventRegistrationTest scans src/ for `record()` calls
        // and requires every event it finds to exist in the canonical
        // subsystem map in bcc-core.php. It reads literals on a single line,
        // so the tidier `record(self::SUBSYSTEM, self::EVENTS[$type])` is
        // INVISIBLE to it — and an event the scan cannot see is an event the
        // map is not forced to declare, which is the drift that check
        // exists to prevent. Written out so the scan can read it.
        if (class_exists('\\BCC\\Core\\Observability\\DegradationMetrics')) {
            if ($metaType === 'user') {
                \BCC\Core\Observability\DegradationMetrics::record('meta_cache', 'user_meta_read');
            } else {
                \BCC\Core\Observability\DegradationMetrics::record('meta_cache', 'post_meta_read');
            }
        }

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-core] meta cache read failed; nothing cached', [
                'meta_type' => $metaType,
                'ids'       => count($objectIds),
            ]);
        }
    }
}
