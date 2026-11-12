<?php

namespace BCC\Core\Cron;

use BCC\Core\Repositories\OptionCleanupRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Garbage-collects expired rate-limit rows from wp_options — the body of
 * the `bcc_core_rl_cleanup` cron (every 30 minutes, under an advisory lock
 * the caller holds).
 *
 * F-126: this never deleted anything. It selected rows with a range,
 * `option_name >= prefix AND < prefix.'~'`, assuming `~` sorts above every
 * letter and digit. Under wp_options' utf8mb4_unicode_ci / unicode_520_ci
 * collation `~` sorts BELOW the hex digits every bucket key continues
 * with, so the range matched no bucket row. Every rate-limit row ever written
 * stayed. Rows are now selected with an escaped prefix LIKE, which is
 * collation-proof and still an index range scan on option_name.
 *
 * It also never covered `_transient_timeout_bcc_rl_*`: the companion row
 * bcc-trust's RateLimitRepository writes for every fresh bucket. WordPress's
 * own delete_expired_transients() only reaps a main row and its timeout row
 * as a PAIR, so once the main row is gone the companion is never deleted.
 * Its value is a bare unix expiry, which the same expiry test reads.
 *
 * Throughput: the old loop stopped after 10 batches (10,000 rows) per range
 * per tick, a fixed ceiling that any sustained burst of fresh buckets could
 * outrun for good. The drain now runs until a short batch, a wall-clock
 * budget, or a per-prefix batch ceiling (termination does not depend on the
 * clock), and reports whether it stopped with work left.
 */
final class RateLimitRowCleanup
{
    /** Option-name prefixes of every rate-limit row family. */
    public const PREFIXES = [
        // Throttle's removed wp_options fallback: no new rows, old ones linger.
        '_bcc_rl_',
        // bcc-trust RateLimiter buckets: "count|expiry".
        '_transient_bcc_rl_',
        // Their WP-transient timeout companions: bare expiry.
        '_transient_timeout_bcc_rl_',
    ];

    public const BATCH_SIZE = 1000;

    /** Wall-clock budget for one tick, across every prefix. */
    public const TIME_BUDGET_SECONDS = 20.0;

    /** Hard ceiling per prefix per tick: 200 × 1000 = 200,000 rows. */
    public const MAX_BATCHES_PER_PREFIX = 200;

    /**
     * @param (callable(): float)|null $clock Seconds; injectable for tests.
     * @return array{deleted: int, failed: list<string>, backlog: bool}
     *         `failed`: prefixes whose DELETE errored (their loop stops,
     *         the next prefix still runs). `backlog`: the tick stopped on
     *         the budget or the ceiling with expired rows possibly left.
     */
    public static function run(?callable $clock = null): array
    {
        $clock    = $clock ?? static fn (): float => microtime(true);
        $deadline = $clock() + self::TIME_BUDGET_SECONDS;

        $deleted = 0;
        $failed  = [];
        $backlog = false;

        foreach (self::PREFIXES as $prefix) {
            $drained = false;
            for ($i = 0; $i < self::MAX_BATCHES_PER_PREFIX; $i++) {
                if ($clock() >= $deadline) {
                    return ['deleted' => $deleted, 'failed' => $failed, 'backlog' => true];
                }

                $n = OptionCleanupRepository::deleteExpiredByPrefix($prefix, self::BATCH_SIZE);
                if ($n === null) {
                    $failed[] = $prefix;
                    $drained  = true; // stop this prefix; not a backlog signal
                    break;
                }

                $deleted += $n;
                if ($n < self::BATCH_SIZE) {
                    $drained = true;
                    break;
                }
            }

            if (!$drained) {
                $backlog = true;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'backlog' => $backlog];
    }
}
