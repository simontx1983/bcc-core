<?php

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bounded wp_options cleanup for rate-limit rows that never auto-expire.
 *
 * The Throttle DB fallback and the RateLimiter write rows to
 * wp_options with an embedded "|<expiry>" suffix. This repository
 * deletes expired rows in LIMIT-chunked batches so the cron worker
 * never holds a long transaction on the options table.
 *
 * Pure data access — concurrency control (advisory lock, dedup) is
 * the caller's responsibility.
 */
final class OptionCleanupRepository
{
    /**
     * Delete up to $batchSize expired rows in the half-open range
     * [$rangeStart, $rangeEnd). The option_value is expected to be
     * in the form "<count>|<unix_expiry>" — the expiry is parsed via
     * SUBSTRING_INDEX and compared to UNIX_TIMESTAMP().
     *
     * Returns the number of rows deleted, or null on DB error.
     */
    public static function deleteExpiredRange(string $rangeStart, string $rangeEnd, int $batchSize = 1000): ?int
    {
        global $wpdb;

        $batchSize = $batchSize > 0 ? $batchSize : 1000;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name >= %s AND option_name < %s
               AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < UNIX_TIMESTAMP()
             LIMIT %d",
            $rangeStart,
            $rangeEnd,
            $batchSize
        ));

        if ($result === false) {
            return null;
        }

        return (int) $result;
    }

    /**
     * Delete every wp_options row whose option_name is in the half-open
     * range [$prefix, $prefix . '~'). Used by plugin-level migration
     * scripts to purge legacy option keys whose payload shape does not
     * match the "count|expiry" format and therefore cannot go through
     * deleteExpiredRange().
     *
     * The '~' (0x7E) suffix is one codepoint above any normal ASCII
     * character, so the range safely terminates after every key matching
     * the given prefix. Deletes in LIMIT-chunked batches (matching
     * deleteExpiredRange) so a large legacy keyspace never holds one
     * long row-locking DELETE on the options table. Returns the total
     * number of rows deleted, or null on DB error (partial progress is
     * discarded from the count; already-deleted rows stay deleted).
     */
    public static function deleteByPrefix(string $prefix, int $batchSize = 1000): ?int
    {
        global $wpdb;

        if ($prefix === '') {
            return 0;
        }

        $batchSize  = $batchSize > 0 ? $batchSize : 1000;
        $rangeStart = $prefix;
        $rangeEnd   = $prefix . '~';

        // Hard iteration ceiling guarantees termination (1000 batches ×
        // 1000 rows = 1M rows — far beyond any real install); if it is
        // ever hit, the remainder is swept on the next run.
        $total = 0;
        for ($i = 0; $i < 1000; $i++) {
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name >= %s AND option_name < %s
                 LIMIT %d",
                $rangeStart,
                $rangeEnd,
                $batchSize
            ));

            if ($result === false) {
                return null;
            }

            $total += (int) $result;
            if ((int) $result < $batchSize) {
                break;
            }
        }

        return $total;
    }

    /**
     * Last DB error text from the shared $wpdb connection.
     * Exposed so the caller can log the underlying failure without
     * touching $wpdb directly.
     */
    public static function lastError(): string
    {
        global $wpdb;
        return (string) $wpdb->last_error;
    }
}
