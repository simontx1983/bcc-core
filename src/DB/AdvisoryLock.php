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
        // Distinguish NULL (driver/permission error) from 0 (held by peer).
        // Prior (int)-cast collapsed both to 0, so transient MySQL errors
        // silently became "locked elsewhere" and cron handlers no-op'd
        // indefinitely without any log trail. Explicit null-check surfaces
        // the failure class so callers (and operators) can react.
        $raw = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $key, $timeout)
        );
        if ($raw === null) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-core] AdvisoryLock::acquire GET_LOCK returned NULL', [
                    'key'     => $key,
                    'timeout' => $timeout,
                    'db_error' => $wpdb->last_error,
                ]);
            }
            return false;
        }
        return (int) $raw === 1;
    }

    /**
     * Release a MySQL advisory lock previously acquired by this session.
     *
     * RELEASE_LOCK return values:
     *   - 1    → lock was held by this session and is now released.
     *   - 0    → lock exists but was not held by this session (no-op).
     *   - NULL → lock did not exist, OR the driver errored.
     *
     * The 0 path is not an error — releasing a lock you do not own is
     * normal for finally-block semantics.  The NULL path is ambiguous on
     * MySQL: "did not exist" looks identical to "driver error" at this
     * call site.  We log the last_error text when it is non-empty so a
     * genuine driver failure is traceable; a clean "did not exist" NULL
     * leaves last_error blank and stays silent.
     */
    public static function release(string $key): void
    {
        global $wpdb;
        $raw = $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)
        );

        if ($raw === null && !empty($wpdb->last_error)) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-core] AdvisoryLock::release RELEASE_LOCK errored', [
                    'key'      => $key,
                    'db_error' => (string) $wpdb->last_error,
                ]);
            }
        }
    }
}
