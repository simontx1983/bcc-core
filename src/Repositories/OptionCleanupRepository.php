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
