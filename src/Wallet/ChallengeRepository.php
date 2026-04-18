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

        // Check if we are already inside an outer transaction.
        // If so, use SAVEPOINT to avoid issuing a nested START TRANSACTION
        // (which would implicitly commit the outer one in MySQL).
        $inTransaction = (bool) $wpdb->get_var("SELECT @@in_transaction");

        if ($inTransaction) {
            $wpdb->query('SAVEPOINT bcc_challenge_consume');
        } else {
            $wpdb->query('START TRANSACTION');
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
                if ($inTransaction) {
                    $wpdb->query('ROLLBACK TO SAVEPOINT bcc_challenge_consume');
                } else {
                    $wpdb->query('ROLLBACK');
                }
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
                if ($inTransaction) {
                    $wpdb->query('RELEASE SAVEPOINT bcc_challenge_consume');
                } else {
                    $wpdb->query('COMMIT');
                }
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
                if ($inTransaction) {
                    $wpdb->query('ROLLBACK TO SAVEPOINT bcc_challenge_consume');
                } else {
                    $wpdb->query('ROLLBACK');
                }
                $committed = true;
                return null;
            }

            if ($inTransaction) {
                $wpdb->query('RELEASE SAVEPOINT bcc_challenge_consume');
            } else {
                $wpdb->query('COMMIT');
            }
            $committed = true;

            return $challenge;
        } catch (\Throwable $e) {
            if (!$committed) {
                if ($inTransaction) {
                    $wpdb->query('ROLLBACK TO SAVEPOINT bcc_challenge_consume');
                } else {
                    $wpdb->query('ROLLBACK');
                }
            }
            throw $e;
        } finally {
            if (!$committed) {
                if ($inTransaction) {
                    $wpdb->query('ROLLBACK TO SAVEPOINT bcc_challenge_consume');
                } else {
                    $wpdb->query('ROLLBACK');
                }
            }
        }
    }

}
