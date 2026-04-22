<?php

namespace BCC\Core\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MySQL session-scoped advisory locks.
 *
 * Thin wrapper around `GET_LOCK` / `RELEASE_LOCK` so service classes do not
 * touch `$wpdb` directly. Locks are session-scoped: MySQL releases them
 * automatically when the connection drops, which means a crashed PHP worker
 * cannot leave a stale lock behind. That property is what makes these the
 * right primitive for cron deduplication and for narrowing race windows
 * around critical sections.
 *
 * NOT a substitute for the wp_options/object-cache lock used by
 * `BCC\PeepSo\Repositories\LockRepository` — that one survives PHP crashes
 * via TTL self-healing and is the right tool when you need a lock to outlive
 * the request that took it. Use this when you want the opposite: automatic
 * release on disconnect.
 *
 * Class is non-final by design so plugin-specific lock classes can extend it
 * to add domain-flavoured key namespacing while inheriting the primitive.
 */
class AdvisoryLock
{
    /**
     * Acquire a MySQL advisory lock.
     *
     * @param string $key     Lock name (max 64 chars on MySQL ≥ 5.7).
     * @param int    $timeout Seconds to wait for the lock (0 = non-blocking).
     * @return bool True if acquired, false if held by another session or on error.
     */
    public static function acquire(string $key, int $timeout = 0): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $key, $timeout)
        ) === 1;
    }

    /**
     * Release a MySQL advisory lock previously acquired by this session.
     *
     * Silently no-ops if the lock is not held — releasing a lock you do not
     * own is not an error condition. Callers in `finally` blocks therefore
     * do not need a guard.
     */
    public static function release(string $key): void
    {
        global $wpdb;
        $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)
        );
    }
}
