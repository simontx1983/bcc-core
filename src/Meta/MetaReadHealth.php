<?php
/**
 * MetaReadHealth — did a meta-cache read FAIL in this request? (F-459)
 *
 * Request-scoped only, and deliberately so: it answers "can the meta I am
 * about to read be trusted *right now*", which is a property of this
 * request's database health and must not outlive it. Persisting it would
 * turn one transient fault into a sticky refusal.
 *
 * ⚠ NOTHING READS THIS YET, and that is intentional. {@see MetaCacheGuard}
 * stops a failed meta read from being CACHED, which is the containment half
 * and carries no behaviour change while the database is healthy. It does not
 * make `get_post_meta()` fail closed — a failed read still answers `''` for
 * that one request, because `get_metadata_raw()` turns a short-circuited
 * `update_meta_cache()` into `null` and then into `''`. Closing that
 * residual means teaching individual gates to refuse, which is F-460 /
 * F-461's job and a user-visible change that deserves its own review.
 *
 * This class exists so those tickets have a signal to consume instead of
 * re-deriving one, and so the failure is observable now
 * ({@see \BCC\Core\Observability\DegradationMetrics} subsystem
 * `meta_cache`) before anything starts refusing on it.
 *
 * ⚠ Per-id as well as per-type. A gate deciding about ONE post should not
 * refuse because an unrelated batch failed earlier in the request — the
 * fail-closed direction is expensive, and over-broad refusal is how a
 * containment fix turns into an outage.
 *
 * @package BCC\Core\Meta
 */

namespace BCC\Core\Meta;

if (!defined('ABSPATH')) {
    exit;
}

final class MetaReadHealth
{
    /**
     * Object ids whose meta read failed this request, by meta type.
     *
     * @var array<string, array<int, true>>
     */
    private static array $failed = [];

    /**
     * Record that the meta read for these ids could not be trusted.
     *
     * @param list<int> $objectIds
     */
    public static function markFailed(string $metaType, array $objectIds): void
    {
        foreach ($objectIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                self::$failed[$metaType][$id] = true;
            }
        }
    }

    /** Did any meta read of this type fail in this request? */
    public static function failed(string $metaType): bool
    {
        return (self::$failed[$metaType] ?? []) !== [];
    }

    /** Did the meta read for THIS object fail in this request? */
    public static function failedFor(string $metaType, int $objectId): bool
    {
        return isset(self::$failed[$metaType][$objectId]);
    }

    /**
     * Ids whose meta read failed, for logging and tests.
     *
     * @return list<int>
     */
    public static function failedIds(string $metaType): array
    {
        return array_map('intval', array_keys(self::$failed[$metaType] ?? []));
    }

    /**
     * Forget everything. For tests, and for a long-running process (WP-CLI,
     * a cron worker) that handles many logical requests in one PHP process —
     * where "request-scoped" has to be asserted rather than assumed.
     */
    public static function reset(): void
    {
        self::$failed = [];
    }
}
