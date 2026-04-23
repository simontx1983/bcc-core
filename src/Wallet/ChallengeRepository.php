<?php
/**
 * ChallengeRepository — transactional storage for wallet signing challenges.
 *
 * Challenges are stored as WordPress transients (wp_options rows).
 * This repository encapsulates the raw $wpdb access needed for
 * atomic consume (SELECT … FOR UPDATE + DELETE inside a transaction).
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
     * Store a challenge payload as a WordPress transient.
     *
     * @param string $key     Transient key (without the `_transient_` prefix).
     * @param array<string, mixed> $payload Challenge data to store.
     * @param int    $ttl     Time-to-live in seconds.
     */
    public static function store(string $key, array $payload, int $ttl): void
    {
        set_transient($key, $payload, $ttl);
    }

    /**
     * Atomically retrieve and delete a challenge from wp_options.
     *
     * Uses a DB transaction with SELECT … FOR UPDATE to guarantee that
     * only one concurrent request can consume a given challenge. The
     * row-level lock is held until COMMIT, so a second request will
     * either block (and then find the row deleted) or find no row at all.
     *
     * @param string $key Transient key (without the `_transient_` prefix).
     * @return array<string, mixed>|null The deserialized challenge, or null if
     *                                   the challenge does not exist or is not a valid array.
     */
    public static function consume(string $key): ?array
    {
        global $wpdb;

        $optionName = '_transient_' . $key;
        $timeoutKey = '_transient_timeout_' . $key;

        // Detect outer transaction state. A NULL return from @@in_transaction
        // means the driver could not determine transaction state (connection
        // reset, permission anomaly on managed MySQL). Proceeding would risk
        // either (a) START TRANSACTION implicitly committing a real outer tx,
        // or (b) treating a non-transactional session as transactional and
        // issuing ROLLBACK TO a non-existent savepoint. Fail-closed instead.
        $rawInTx = $wpdb->get_var("SELECT @@in_transaction");
        if ($rawInTx === null) {
            throw new \RuntimeException(
                'ChallengeRepository::consume: cannot determine transaction state — refusing to proceed'
            );
        }
        $inTransaction = (int) $rawInTx === 1;

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
