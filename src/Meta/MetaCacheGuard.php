<?php
/**
 * MetaCacheGuard — a meta read is verified before its result is cached (F-459).
 *
 * ── What it guarantees ─────────────────────────────────────────
 *
 * A post- or user-meta read is run through {@see ProbesReads} and its result
 * is cached ONLY when the rows can be trusted. A read that cannot be trusted
 * caches nothing, so the next request reads again instead of being served a
 * remembered answer.
 *
 * On a healthy database there is NO behaviour change: one query, the same
 * cache contents, the same return value.
 *
 * ⚠ Containment, not closure. The request the failure happened in still
 * reads the meta as absent. What this removes is the PERSISTENCE. Teaching
 * individual gates to refuse is F-460 / F-461, consuming
 * {@see MetaReadHealth}.
 *
 * ── Why the filter is shaped the way it is ───────────────────────
 *
 * ⛔ On SUCCESS it returns **null**, and that is load-bearing.
 * `update_meta_cache()` coerces any non-null short-circuit with
 * `return (bool) $check;`, and `get_metadata_raw()` then reads
 * `$meta_cache[ $object_id ] ?? null`. Returning the cache array — or
 * `true` — would make every object's FIRST meta read in a request answer
 * '' ON A HEALTHY DATABASE. Returning null after priming lets core's own
 * `wp_cache_get_multiple()` find everything and return its real array
 * without a second query. There is a test pinning this.
 *
 * On an untrusted read it caches nothing and returns **false** — the one
 * thing the filter's bool coercion is good for.
 *
 * ── Why ProbesReads rather than get_results() ───────────────────
 *
 * {@see \BCC\Core\Repositories\ProbesReads} checks `query()`'s OWN return
 * value (`=== false`, because a SELECT returns the row count) as well as
 * `last_error`, having cleared it first. `get_results()` discards that
 * return value, and `last_error` alone is not an exhaustive failure signal.
 * Three signals rather than one, and it is reused rather than
 * reimplemented (§11).
 *
 * ── Equivalence ───────────────────────────────────────────────
 *
 * What this writes is what core writes for the same rows: an empty entry for
 * an id with no rows (that entry is what stops a second read re-querying, so
 * it is part of the equivalence), several values under one key in `meta_id`
 * order, and an empty-string value stored rather than dropped.
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

            // Cache NOTHING and short-circuit. This request reads the meta
            // as absent — see the class docblock — but the next one reads
            // again rather than being served a remembered answer.
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
