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
     * Delete up to $batchSize expired rows whose option_name starts with
     * $prefix. The option_value is "<count>|<unix_expiry>" or a bare
     * "<unix_expiry>" (a transient timeout row): the text after the last
     * '|' (the whole value when there is none) is the expiry, compared to
     * UNIX_TIMESTAMP().
     *
     * ⚠ F-126: select by an ESCAPED PREFIX LIKE, never by a
     * `[prefix, prefix.'~')` range. Under wp_options' utf8mb4_unicode_ci /
     * unicode_520_ci collation `~` sorts BELOW digits and letters, so that
     * range matches none of the md5-hex keys after the prefix: the old
     * deleteExpiredRange() deleted nothing. A literal-prefix LIKE is still
     * an index range scan on option_name.
     *
     * Returns the number of rows deleted, or null on DB error.
     */
    public static function deleteExpiredByPrefix(string $prefix, int $batchSize = 1000): ?int
    {
        global $wpdb;

        if ($prefix === '') {
            return 0;
        }

        $batchSize = $batchSize > 0 ? $batchSize : 1000;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < UNIX_TIMESTAMP()
             LIMIT %d",
            $wpdb->esc_like($prefix) . '%',
            $batchSize
        ));

        if ($result === false) {
            return null;
        }

        return (int) $result;
    }

    /**
     * Delete every wp_options row whose option_name starts with $prefix.
     * Used by plugin-level migration scripts to purge legacy option keys
     * whose payload shape does not match the "count|expiry" format and
     * therefore cannot go through deleteExpiredByPrefix().
     *
     * Selected by an escaped prefix LIKE (an index range scan on
     * option_name). It used to be the range [$prefix, $prefix . '~'), on the
     * belief that '~' sorts above every normal character. That holds for a
     * binary collation only: under wp_options' utf8mb4_unicode_ci /
     * unicode_520_ci '~' sorts BELOW digits and letters, so the range
     * missed most keys (F-126). Deletes in LIMIT-chunked batches so a large
     * legacy keyspace never holds one long row-locking DELETE on the
     * options table. Returns the total number of rows deleted, or null on
     * DB error (partial progress is discarded from the count;
     * already-deleted rows stay deleted).
     */
    public static function deleteByPrefix(string $prefix, int $batchSize = 1000): ?int
    {
        // ⚠⚠ READ THIS BEFORE PASSING A `bcc_` PREFIX. An `init` sweep used
        // to delete `bcc_disputes_`, `bcc_onchain_` and `bcc_signals_` here
        // on the grounds that those plugins had been removed. They were
        // MERGED INTO bcc-trust, and the merged code kept the old option and
        // transient prefixes — so all three are LIVE, and two of them are
        // generated per chain and per user, which no literal keep-list can
        // enumerate. See the removal tombstone in `bcc-core.php` and the
        // rule in `OptionSweepGuardTest`: anything under `bcc_` must be
        // swept by EXACT KEY, never by prefix.
        //
        // This method is correct and is not what went wrong; the prefix list
        // was.
        global $wpdb;

        if ($prefix === '') {
            return 0;
        }

        $batchSize = $batchSize > 0 ? $batchSize : 1000;
        $like      = $wpdb->esc_like($prefix) . '%';

        // Hard iteration ceiling guarantees termination (1000 batches ×
        // 1000 rows = 1M rows — far beyond any real install); if it is
        // ever hit, the remainder is swept on the next run.
        $total = 0;
        for ($i = 0; $i < 1000; $i++) {
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 LIMIT %d",
                $like,
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
