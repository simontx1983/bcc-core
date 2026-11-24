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
    use \BCC\Core\Repositories\ProbesReads;

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
     * Every LIVE challenge whose key starts with $keyPrefix, keyed by its
     * transient key (without the `_transient_` prefix), in key order.
     *
     * Same direct-DB read as peek(), and the same filtering: corrupt and
     * expired rows are skipped, never deleted here. Bounded by $limit.
     * The prefix is LIKE-escaped (`_` in the key prefixes is a wildcard).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function peekByPrefix(string $keyPrefix, int $limit = 20): array
    {
        global $wpdb;

        /** @var list<array{option_name: string, option_value: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE %s
             ORDER BY option_name
             LIMIT %d",
            $wpdb->esc_like('_transient_' . $keyPrefix) . '%',
            max(1, $limit)
        ), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $challenge = maybe_unserialize((string) ($row['option_value'] ?? ''));
            if (!is_array($challenge) || time() > (int) ($challenge['expires_at'] ?? 0)) {
                continue;
            }
            $out[substr((string) $row['option_name'], strlen('_transient_'))] = $challenge;
        }
        return $out;
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

        // Contract: consume() owns its transaction. Inside a caller's, its
        // DELETE would roll back with the caller's work (the nonce made
        // replayable), and START TRANSACTION below would commit the caller's
        // work implicitly. transactionState() asks the connection with a
        // SAVEPOINT / RELEASE probe that answers the same on MySQL 8 and
        // MariaDB.
        //
        // ⚠ It replaces an INFORMATION_SCHEMA.INNODB_TRX lookup that missed
        // open transactions on MySQL 8: the view is cached for ~100 ms, and
        // a transaction is not listed until it first touches InnoDB, so a
        // consume() right after START TRANSACTION was not caught. (The
        // `SELECT @@in_transaction` tried before that does not exist on
        // MySQL 8 and crashed every wallet login.)
        $state = self::transactionState();
        if ($state === self::TX_INSIDE) {
            throw new \LogicException(
                'ChallengeRepository::consume must be called outside any open transaction ' .
                '(nonce-replay hazard under outer-transaction rollback)'
            );
        }
        if ($state !== self::TX_OUTSIDE) {
            // The probe could not tell. Refuse rather than guess: the
            // challenge is reported unavailable and is left where it is.
            return null;
        }

        if ($wpdb->query('START TRANSACTION') === false) {
            // Fail closed: without the transaction the FOR UPDATE below is a
            // plain read, so the nonce wouldn't be atomically consumed and
            // could be replayed under a concurrent request. Treat the
            // challenge as unavailable and reject rather than proceed.
            return null;
        }

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

            // Corrupted OR expired — either way the row must not be
            // consumable: delete it to prevent replay, then commit so the
            // DELETE persists. Expiry mirrors peek(); the service layer
            // re-checks it too — this is defense-in-depth so a direct
            // repository caller can never consume a stale nonce.
            if (!is_array($challenge)
                || time() > (int) ($challenge['expires_at'] ?? 0)
            ) {
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

    /** transactionState(): an explicit or implicit transaction is open. */
    public const TX_INSIDE = 'inside';

    /** transactionState(): no transaction is open. */
    public const TX_OUTSIDE = 'outside';

    /** transactionState(): the probe failed in a way that answers neither. */
    public const TX_UNKNOWN = 'unknown';

    /**
     * Take a challenge INSIDE the caller's open transaction: the
     * use-once step of a write that must either all happen or not at all
     * (the validator memo claim consumes its challenge in the same
     * transaction as the claim it pays for, design §5.2).
     *
     * SELECT … FOR UPDATE, then DELETE of the value row, which must affect
     * exactly one row. Nothing is committed or rolled back here: the
     * caller's transaction decides, so a rollback restores the challenge.
     *
     * - Refused (LogicException) unless transactionState() is TX_INSIDE:
     *   outside a transaction the delete would commit on its own, apart
     *   from the write it pays for. A probe that cannot tell refuses too.
     * - Null when there is no such challenge, it is corrupt or it expired
     *   (nothing is deleted).
     * - RuntimeException when the read or the delete fails; the caller
     *   rolls back.
     *
     * @param string $key Transient key (without the `_transient_` prefix).
     * @return array<string, mixed>|null
     */
    public static function consumeInTransaction(string $key): ?array
    {
        global $wpdb;

        if (self::transactionState() !== self::TX_INSIDE) {
            throw new \LogicException(
                'ChallengeRepository::consumeInTransaction must be called inside an open transaction'
            );
        }

        $optionName = '_transient_' . $key;

        // Through ProbesReads: a failed read must not pass for "no row",
        // nor wpdb's pre-flush bail hand back the previous query's row.
        $rows = self::trustedRows($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name = %s
             FOR UPDATE",
            $optionName
        ));
        if ($rows === null) {
            throw new \RuntimeException('challenge read failed');
        }
        if ($rows === []) {
            return null;
        }

        $challenge = maybe_unserialize((string) ($rows[0]->option_value ?? ''));
        if (!is_array($challenge) || time() > (int) ($challenge['expires_at'] ?? 0)) {
            return null;
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            $optionName
        ));
        if ($deleted !== 1) {
            throw new \RuntimeException('challenge delete did not remove exactly one row');
        }
        // The timeout row only feeds WordPress's transient GC; same transaction.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            '_transient_timeout_' . $key
        ));

        /** @var array<string, mixed> $challenge */
        return $challenge;
    }

    /**
     * Change a live challenge in its own transaction (SELECT … FOR UPDATE,
     * then UPDATE): a compare-and-set, so concurrent callers see each
     * other's writes. $change receives the payload and returns the new one,
     * or null to leave it as it is (a refusal).
     *
     * Refused (LogicException) unless transactionState() is TX_OUTSIDE:
     * START TRANSACTION inside an open transaction would commit the
     * caller's work implicitly. Null when the challenge is missing,
     * corrupt or expired, when $change refuses, or when the transaction
     * cannot be had or the write fails (rolled back).
     *
     * @param string $key Transient key (without the `_transient_` prefix).
     * @param callable(array<string, mixed>): (array<string, mixed>|null) $change
     * @return array<string, mixed>|null the stored payload
     */
    public static function update(string $key, callable $change): ?array
    {
        global $wpdb;

        if (self::transactionState() !== self::TX_OUTSIDE) {
            throw new \LogicException(
                'ChallengeRepository::update must be called outside any open transaction'
            );
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            return null;
        }

        $optionName = '_transient_' . $key;
        $result     = null;
        try {
            $rows    = self::trustedRows($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options}
                 WHERE option_name = %s
                 FOR UPDATE",
                $optionName
            ));
            $current = isset($rows[0]) ? maybe_unserialize((string) ($rows[0]->option_value ?? '')) : null;
            if (is_array($current) && time() <= (int) ($current['expires_at'] ?? 0)) {
                /** @var array<string, mixed> $current */
                $next = $change($current);
                if ($next !== null) {
                    $written = $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                        maybe_serialize($next),
                        $optionName
                    ));
                    $result = $written === false ? null : $next;
                }
            }
        } finally {
            $wpdb->query($result === null ? 'ROLLBACK' : 'COMMIT');
        }
        if ($result !== null && function_exists('wp_cache_delete')) {
            wp_cache_delete($key, 'transient');
            wp_cache_delete($optionName, 'options');
        }
        return $result;
    }

    /**
     * Is a transaction open on this connection? A probe that works the same
     * on MySQL 8 and MariaDB: SAVEPOINT, then RELEASE SAVEPOINT. Inside a
     * transaction (START TRANSACTION, BEGIN, or autocommit off) the release
     * succeeds. Outside one the savepoint ends with its implicit
     * transaction, so the release fails with error 1305 "SAVEPOINT … does
     * not exist".
     *
     * ⚠ Any OTHER failure is TX_UNKNOWN, never TX_OUTSIDE, and every caller
     * refuses on it.
     * ⚠ The savepoint name is unique per call: a savepoint with a caller's
     * name (bcc-trust's TransactionManager uses sp_<depth>_<rand>) would
     * silently replace theirs.
     *
     * (INFORMATION_SCHEMA.INNODB_TRX cannot answer this on MySQL 8: the view
     * is served from a cache refreshed at most every ~100 ms, and a
     * transaction is not listed until it first touches InnoDB.)
     *
     * Errors are suppressed for the probe and `last_error` is cleared after
     * it, so an expected 1305 is neither logged nor mistaken later for a
     * failure of the next query.
     */
    public static function transactionState(): string
    {
        global $wpdb;

        $name       = 'bcc_txprobe_' . bin2hex(random_bytes(8));
        $suppressed = $wpdb->suppress_errors(true);
        try {
            self::clearLastError();
            if ($wpdb->query('SAVEPOINT ' . $name) === false) {
                return self::TX_UNKNOWN;
            }
            if ($wpdb->query('RELEASE SAVEPOINT ' . $name) !== false) {
                return self::TX_INSIDE;
            }
            return self::lastErrorMessage() === 'SAVEPOINT ' . $name . ' does not exist'
                ? self::TX_OUTSIDE
                : self::TX_UNKNOWN;
        } finally {
            self::clearLastError();
            $wpdb->suppress_errors($suppressed);
        }
    }

    /** The driver's last error message (a helper, for the reason ProbesReads::clearLastError() gives). */
    private static function lastErrorMessage(): string
    {
        global $wpdb;
        return (string) $wpdb->last_error;
    }
}
