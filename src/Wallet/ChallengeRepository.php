<?php
/**
 * ChallengeRepository — transactional storage for wallet signing challenges.
 *
 * Challenges are written directly to wp_options (bypassing the WordPress
 * transient API's object-cache routing) so consume() can rely on a single
 * atomic primitive — SELECT … FOR UPDATE + DELETE inside a transaction —
 * regardless of whether a persistent object cache is configured. The
 * earlier two-strategy design used wp_cache_add() as a claim token under
 * an external object cache; that primitive is non-atomic across processes
 * on backends like LiteSpeed Object Cache (its add() only checks the
 * request-local cache array), allowing the same nonce to be consumed
 * twice. The DB-only path closes that hole at the cost of one extra
 * round-trip when a fast cache backend was previously serving the read.
 *
 * @package BCC\Core\Wallet
 */

namespace BCC\Core\Wallet;

if (!defined('ABSPATH')) {
    exit;
}

final class ChallengeRepository
{
    /**
     * Store a challenge payload as a wp_options row using the WordPress
     * transient layout (`_transient_<key>` + `_transient_timeout_<key>`).
     *
     * Writes go to wp_options directly so that a configured object cache
     * does not shadow the row that consume() will read with
     * SELECT … FOR UPDATE. The transient layout is preserved so future
     * GC paths (WP core cleanup, bcc-core janitors) keep working.
     *
     * @param string $key     Transient key (without the `_transient_` prefix).
     * @param array<string, mixed> $payload Challenge data to store.
     * @param int    $ttl     Time-to-live in seconds.
     */
    public static function store(string $key, array $payload, int $ttl): void
    {
        global $wpdb;

        $optionName = '_transient_' . $key;
        $timeoutKey = '_transient_timeout_' . $key;
        $expiresAt  = time() + $ttl;
        $serialized = maybe_serialize($payload);

        // Direct INSERT … ON DUPLICATE KEY UPDATE bypasses set_transient()'s
        // cache routing so the same row can be read with SELECT … FOR UPDATE
        // by consume() under any object-cache configuration.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
            $optionName,
            $serialized
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
            $timeoutKey,
            (string) $expiresAt
        ));

        // Best-effort: evict any cached transient values left from earlier
        // (cache-routed) writes so a stale cache entry cannot shadow the
        // new row on the next read.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($key, 'transient');
            wp_cache_delete($optionName, 'options');
            wp_cache_delete($timeoutKey, 'options');
        }
    }

    /**
     * Non-destructive read of a stored challenge.
     *
     * Reads wp_options directly — same rationale as store/consume: an
     * external object-cache drop-in (e.g. wp-content/object-cache.php)
     * makes `get_transient()` route only through the 'transient' cache
     * group, which the direct-write store() never populates. Using the
     * options table as the single source of truth keeps peek/consume in
     * sync regardless of whether persistent caching is active.
     *
     * Returns null when the row is missing, payload is corrupt, or the
     * payload's `expires_at` has elapsed. Does NOT delete on expiry —
     * the GC happens when consume() runs (or when WP transient cleanup
     * sweeps the wp_options table).
     *
     * @param string $key Transient key (without the `_transient_` prefix).
     * @return array<string, mixed>|null
     */
    public static function peek(string $key): ?array
    {
        global $wpdb;

        $optionName = '_transient_' . $key;

        /** @var string|null $serialized */
        $serialized = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name = %s
             LIMIT 1",
            $optionName
        ));

        if ($serialized === null) {
            return null;
        }

        /** @var array<string, mixed>|false $challenge */
        $challenge = maybe_unserialize($serialized);

        if (!is_array($challenge)) {
            return null;
        }

        if (time() > (int) ($challenge['expires_at'] ?? 0)) {
            return null;
        }

        return $challenge;
    }

    /**
     * Atomically retrieve and delete a challenge.
     *
     * Always uses the DB-backed strategy: SELECT … FOR UPDATE + DELETE
     * inside a transaction. This guarantees at-most-one consumer
     * (concurrent callers for the same key either receive the challenge
     * or null) regardless of whether an external object cache is active.
     *
     * @param string $key Transient key (without the `_transient_` prefix).
     * @return array<string, mixed>|null The deserialized challenge, or null.
     */
    public static function consume(string $key): ?array
    {
        return self::consumeFromDatabase($key);
    }

    /**
     * Database-path consume: SELECT … FOR UPDATE + DELETE inside a
     * transaction. Used only when WordPress is NOT using a persistent
     * object cache (so set_transient wrote the value to wp_options).
     *
     * @return array<string, mixed>|null
     */
    private static function consumeFromDatabase(string $key): ?array
    {
        global $wpdb;

        $optionName = '_transient_' . $key;
        $timeoutKey = '_transient_timeout_' . $key;

        // Detect outer transaction state. The earlier `SELECT @@in_transaction`
        // probe is not portable: MySQL 8.0 / MariaDB 10.4–10.6 do not expose
        // it as a system variable (the query fails with "Unknown system
        // variable 'in_transaction'") and `IN_TRANSACTION()` is not a
        // built-in either. The result was a 100% wallet-login crash on
        // every supported MySQL we ship against.
        //
        // Replacement: query INFORMATION_SCHEMA.INNODB_TRX scoped to our
        // own thread. INNODB_TRX is a standard view present in MySQL 5.7+
        // and MariaDB 10.x and lists every InnoDB transaction the engine
        // is currently tracking, keyed by the OS-level connection id from
        // CONNECTION_ID(). A row exists iff this session has an open
        // explicit (or DML-implicit-non-autocommit) transaction. A non-null
        // CONNECTION_ID() guarantees the lookup is meaningful; if CONNECTION_ID()
        // returns null (driver in a degraded state), we fall back to
        // proceeding without the protection — failing closed here would
        // recreate the same 100% outage we're fixing, and the caller
        // contract (consume() runs at the top of the wallet-login REST
        // handler, no outer transaction by design) makes the protection
        // belt-and-braces rather than load-bearing.
        $threadId = $wpdb->get_var('SELECT CONNECTION_ID()');
        $inTransaction = false;
        if ($threadId !== null) {
            $found = $wpdb->get_var($wpdb->prepare(
                'SELECT 1 FROM information_schema.INNODB_TRX
                  WHERE trx_mysql_thread_id = %d
                  LIMIT 1',
                (int) $threadId
            ));
            $inTransaction = $found !== null;
        }

        // Enforce contract: consume() must not run inside a caller's
        // transaction. If the caller's outer transaction later rolls back,
        // our SAVEPOINT-based DELETE is rolled back with it — making the
        // nonce replayable. Require atomic consume to own the whole tx.
        if ($inTransaction) {
            throw new \LogicException(
                'ChallengeRepository::consume must be called outside any open transaction ' .
                '(nonce-replay hazard under outer-transaction rollback)'
            );
        }

        $wpdb->query('START TRANSACTION');

        $committed = false;

        try {
            /** @var string|null $serialized */
            $serialized = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options}
                 WHERE option_name = %s
                 FOR UPDATE",
                $optionName
            ));

            if ($serialized === null) {
                $wpdb->query('ROLLBACK');
                $committed = true;
                return null;
            }

            /** @var array<string, mixed>|false $challenge */
            $challenge = maybe_unserialize($serialized);

            if (!is_array($challenge)) {
                // Corrupted challenge — delete it to prevent replay, then
                // commit so the DELETE persists.
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
                    $optionName,
                    $timeoutKey
                ));
                $wpdb->query('COMMIT');
                $committed = true;
                return null;
            }

            // Delete both the value and timeout rows atomically.
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
                $optionName,
                $timeoutKey
            ));

            if ($deleted === false) {
                $wpdb->query('ROLLBACK');
                $committed = true;
                return null;
            }

            $wpdb->query('COMMIT');
            $committed = true;

            return $challenge;
        } catch (\Throwable $e) {
            if (!$committed) {
                $wpdb->query('ROLLBACK');
                // Mark handled so the finally block does not issue a
                // second ROLLBACK on an already-rolled-back transaction
                // (which poisons $wpdb->last_error across the request
                // and prior caused "SAVEPOINT does not exist" warnings
                // under GTID-strict replication).
                $committed = true;
            }
            throw $e;
        } finally {
            if (!$committed) {
                $wpdb->query('ROLLBACK');
            }
        }
    }

}
