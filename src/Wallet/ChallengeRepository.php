<?php
/**
 * ChallengeRepository — transactional storage for wallet signing challenges.
 *
 * Challenges are stored as WordPress transients. WordPress writes those to
 * the persistent object cache (Redis/Memcached) when one is active — in
 * which case NO wp_options row ever exists — or to wp_options otherwise.
 * The consume path therefore needs two strategies:
 *
 *   - Object-cache path: wp_cache_add() as an atomic claim token so only
 *     one concurrent worker may read+delete the transient. Losers of the
 *     race get null.
 *   - Database path: SELECT … FOR UPDATE + DELETE inside a transaction.
 *
 * The two paths share the same public API so WalletIdentityService never
 * has to branch on the backend.
 *
 * @package BCC\Core\Wallet
 */

namespace BCC\Core\Wallet;

if (!defined('ABSPATH')) {
    exit;
}

final class ChallengeRepository
{
    /** wp_cache group used for the claim token (separate from transients group). */
    private const CLAIM_CACHE_GROUP = 'bcc_wc_claim';

    /** Claim-token TTL — long enough to cover one consume, short enough to self-heal on crash. */
    private const CLAIM_TTL = 10;

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
     * Atomically retrieve and delete a challenge.
     *
     * Dispatches to the object-cache-backed or DB-backed strategy based on
     * whether WordPress is currently using a persistent object cache.
     *
     * Both strategies guarantee at-most-one consumer: concurrent callers
     * for the same key either receive the challenge (winner) or null
     * (loser / missing / malformed).
     *
     * @param string $key Transient key (without the `_transient_` prefix).
     * @return array<string, mixed>|null The deserialized challenge, or null.
     */
    public static function consume(string $key): ?array
    {
        // When a persistent object cache is active, set_transient() stored
        // the value in that cache — NOT in wp_options. Querying wp_options
        // would always return null, so we must consume through the cache.
        if (wp_using_ext_object_cache()) {
            return self::consumeFromCache($key);
        }

        return self::consumeFromDatabase($key);
    }

    /**
     * Object-cache consume: use wp_cache_add as an atomic claim token so
     * only one concurrent worker reads + deletes the transient. The claim
     * token auto-expires in CLAIM_TTL seconds so a crashed worker cannot
     * permanently wedge a challenge.
     *
     * @return array<string, mixed>|null
     */
    private static function consumeFromCache(string $key): ?array
    {
        $claimKey = 'claim_' . $key;

        // Atomic: only one worker wins the add. wp_cache_add returns false
        // if the key already exists OR if the backend is unreachable — in
        // either case the caller must treat the challenge as unconsumable
        // for this request (fail-closed under cache failure).
        if (!wp_cache_add($claimKey, 1, self::CLAIM_CACHE_GROUP, self::CLAIM_TTL)) {
            return null;
        }

        try {
            $value = get_transient($key);
            if (!is_array($value)) {
                // Missing, expired, or corrupted — delete to purge any stale
                // residue and return null.
                delete_transient($key);
                return null;
            }

            // Delete BEFORE returning so a concurrent peek() cannot observe
            // the consumed challenge. delete_transient is idempotent.
            delete_transient($key);

            return $value;
        } finally {
            // Release the claim token so the key is reusable after a
            // legitimate re-generation. Without this the user would have
            // to wait CLAIM_TTL seconds before a new challenge could be
            // consumed for the same wallet.
            wp_cache_delete($claimKey, self::CLAIM_CACHE_GROUP);
        }
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
