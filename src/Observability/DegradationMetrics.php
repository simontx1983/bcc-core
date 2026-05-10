<?php
/**
 * DegradationMetrics — cross-plugin observability counters for silent
 * fallback paths.
 *
 * The platform is intentionally resilient: fail-closed throttles, fail-open
 * caches, queue fallbacks, NullService default-deny shims, LKG cache
 * serving, swallowed audit-log writes. Each is a deliberate engineering
 * decision; collectively they create a class of bug where the platform
 * becomes partially incorrect while appearing healthy.
 *
 * This class is the canonical primitive for making those silent paths
 * visible internally — without removing the resilience. Every fallback
 * site records one bucketed counter; admin / system-health surfaces
 * snapshot the buckets to detect "degraded mode active for the last
 * N hours" before users notice the symptom.
 *
 * Storage shape mirrors {@see \BCC\Trust\Core\Support\PushMetrics}
 * (the canonical model that proved this pattern at the §P1.F push
 * layer). Per-(subsystem, event) per-UTC-hour counters; atomic via
 * `wp_cache_add` + `wp_cache_incr` on persistent object caches;
 * transient fallback for sites without persistent caching (non-atomic,
 * may lose increments under contention — acceptable for an
 * observability metric).
 *
 * Bucket key format: `bcc_deg_{subsystem}_{event}_{YYYYMMDDHH}` (UTC).
 *
 * Subsystems and events are free-form strings the caller chooses, but
 * are sanitized to `[a-z0-9_]+` to keep cache backends portable. Use
 * stable identifiers — these strings appear in dashboards and run
 * histories; renaming them resets the rolling counters.
 *
 * Constitutional posture:
 *   - §VI bcc-core ownership: lives in bcc-core because every plugin
 *     can produce degradation events.
 *   - §V.17–§V.21 no-parallel-systems: this is NOT a parallel system to
 *     PushMetrics. PushMetrics is push-event-specific (attempt /
 *     success / tombstone / failure × push event types); this is
 *     subsystem-keyed for cross-plugin fallback observability.
 *   - §VIII.30 audit logging must never break the mutation path: every
 *     write is non-blocking. Cache write failure falls through to a
 *     transient write which is itself non-blocking — the caller never
 *     sees an exception from this class.
 *
 * @package BCC\Core\Observability
 * @since Phase 1 observability initiative (2026-05-09)
 * @status alive (canonical) — cross-plugin degradation observability primitive.
 */

declare(strict_types=1);

namespace BCC\Core\Observability;

if (!defined('ABSPATH')) {
    exit;
}

final class DegradationMetrics
{
    /** Default event name when callers only need a single bucket per subsystem. */
    public const EVENT_ACTIVATION = 'activation';

    private const KEY_PREFIX  = 'bcc_deg_';
    private const CACHE_GROUP = 'bcc_degradation_metrics';

    /**
     * 2-hour TTL — long enough for the canonical "current + previous
     * hour" admin display, short enough that a stuck transient self-
     * heals without operator intervention. Matches PushMetrics.
     */
    private const TTL = 7200;

    /**
     * Record one occurrence of a degradation event.
     *
     * Non-blocking. Safe to call inside catch blocks, fail-closed
     * branches, fallback paths, and silent-continuation loops.
     *
     * @param string $subsystem Stable identifier for the subsystem
     *                          producing the event (e.g. 'throttle',
     *                          'lkg_search', 'peepso_absence',
     *                          'null_trust_read', 'read_model_legacy').
     *                          Sanitized to [a-z0-9_]+.
     * @param string $event     Variant within the subsystem (e.g.
     *                          'activation', 'fallback_active',
     *                          'served_from_lkg', 'fail_closed').
     *                          Defaults to 'activation' for subsystems
     *                          that only need a single bucket.
     */
    public static function record(string $subsystem, string $event = self::EVENT_ACTIVATION): void
    {
        $subsystem = self::sanitize($subsystem);
        $event     = self::sanitize($event);
        if ($subsystem === '' || $event === '') {
            return;
        }

        self::increment(self::buildKey($subsystem, $event, gmdate('YmdH')));
    }

    /**
     * Read the count for a single (subsystem, event) bucket at a given
     * UTC hour. Returns 0 when the bucket is empty / expired / unset.
     *
     * @param int $timestamp Unix timestamp; the UTC hour-of is what
     *                       gets read. Pass `time()` for the current
     *                       hour or `time() - 3600` for the previous.
     */
    public static function readEvent(string $subsystem, string $event, int $timestamp): int
    {
        $subsystem = self::sanitize($subsystem);
        $event     = self::sanitize($event);
        if ($subsystem === '' || $event === '') {
            return 0;
        }

        return self::readBucket(
            self::buildKey($subsystem, $event, gmdate('YmdH', $timestamp))
        );
    }

    /**
     * Snapshot a subsystem's events for a UTC hour. Caller passes the
     * known event names — there's no global registry. Unknown event
     * names return 0.
     *
     * Used by admin surfaces and `bcc_system_health` callbacks to
     * project per-subsystem fallback rates into the unified health
     * envelope.
     *
     * @param string       $subsystem
     * @param list<string> $events
     * @param int          $timestamp
     * @return array<string, int> event => count
     */
    public static function readSnapshot(string $subsystem, array $events, int $timestamp): array
    {
        $subsystem = self::sanitize($subsystem);
        if ($subsystem === '' || $events === []) {
            return [];
        }

        $hour     = gmdate('YmdH', $timestamp);
        $snapshot = [];
        foreach ($events as $event) {
            $eventKey = self::sanitize($event);
            if ($eventKey === '') {
                continue;
            }
            $snapshot[$eventKey] = self::readBucket(
                self::buildKey($subsystem, $eventKey, $hour)
            );
        }
        return $snapshot;
    }

    private static function buildKey(string $subsystem, string $event, string $hour): string
    {
        return self::KEY_PREFIX . $subsystem . '_' . $event . '_' . $hour;
    }

    /**
     * Sanitize identifier strings to `[a-z0-9_]+` so cache keys remain
     * portable across object-cache backends (Redis tolerates much, but
     * memcached does not). Strips empty results to ''.
     */
    private static function sanitize(string $raw): string
    {
        $cleaned = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', $raw) ?? '');
        return trim($cleaned, '_');
    }

    private static function increment(string $key): void
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            // wp_cache_add seeds the bucket atomically on first hit.
            // wp_cache_incr is atomic INCR on subsequent hits.
            if (wp_cache_add($key, 1, self::CACHE_GROUP, self::TTL)) {
                return;
            }
            $incr = wp_cache_incr($key, 1, self::CACHE_GROUP);
            if ($incr !== false) {
                return;
            }
            // Cache backend hiccup — fall through to transient so the
            // increment is not lost entirely.
        }

        if (function_exists('get_transient') && function_exists('set_transient')) {
            $current = (int) get_transient($key);
            set_transient($key, $current + 1, self::TTL);
        }
    }

    private static function readBucket(string $key): int
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $cached = wp_cache_get($key, self::CACHE_GROUP);
            if ($cached !== false) {
                return (int) $cached;
            }
        }
        if (!function_exists('get_transient')) {
            return 0;
        }
        $value = get_transient($key);
        return $value === false ? 0 : (int) $value;
    }
}
