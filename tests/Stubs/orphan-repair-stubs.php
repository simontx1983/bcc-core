<?php

/**
 * F-542 harness for {@see \BCC\Core\PeepSo\OrphanedPostRepair}.
 *
 * ⚠ `wp_update_post` is shadowed in `BCC\Core\PeepSo`. Verified safe before
 * doing it: OrphanedPostRepair is the ONLY unqualified caller of it in that
 * namespace (every other occurrence under src/PeepSo/ is a comment), so this
 * shadow cannot reach another suite's code — the file-level-stub leak that
 * would otherwise make someone else's assertions vacuous.
 *
 * ⚠ The `$wpdb` double is driven through the REAL
 * `PeepSoActivityRepository::postStatusOrUnknown()` and the REAL ProbesReads
 * trait, not a faked repository. A stubbed tri-state read would prove only
 * that the repair branches on three values, which a reader can already see;
 * what needs proving is that a FAILED read produces the unknown branch.
 */

declare(strict_types=1);

namespace {
    if (!isset($GLOBALS['__bcc_orp'])) {
        $GLOBALS['__bcc_orp'] = [
            // post id => post_status. Absent = no such post.
            'posts'      => [],
            // 'ok' | 'error' | 'preflush', consumed one per posts query.
            'modes'      => [],
            // When true, wp_update_post's own get_post() is treated as having
            // failed: it returns 0 and changes NOTHING. This is the silent
            // no-op that makes the cron the real guarantee.
            'post_read_fails' => false,
            'updates'    => [],   // every wp_update_post payload, in order
            'metrics'    => [],   // [subsystem, event] pairs
            'scheduled'  => [],   // [timestamp, hook, args, group]
            'queries'    => 0,    // posts queries actually executed
            'pins'       => [],   // $_GET seen INSIDE each trash write
            'logs'       => [],   // [message, context] pairs, in order
            // Throw switches. Each one models a DIFFERENT step blowing
            // up, because the guarantee is that one failing step never
            // stops the steps after it.
            'trash_throws'    => false,  // a listener woken by the write
            'log_throws'      => false,  // the file log is unwritable
            'schedule_throws' => false,  // the scheduler is down
        ];
    }
}

namespace BCC\Core\Observability {
    if (!\class_exists(DegradationMetrics::class, false)) {
        final class DegradationMetrics
        {
            public const EVENT_ACTIVATION = 'activation';

            public static function record(string $subsystem, string $event = self::EVENT_ACTIVATION): void
            {
                $GLOBALS['__bcc_orp']['metrics'][] = [$subsystem, $event];
            }
        }
    }
}

namespace BCC\Core\Log {
    // ⚠ The real Logger writes to the filesystem. This records the
    // CONTEXT as well as the message, because the post_id in the context
    // is the whole point of the log line the ops runbook reads.
    if (!\class_exists('BCC\Core\Log\Logger', false)) {
        class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                if ($GLOBALS['__bcc_orp']['log_throws']) {
                    throw new \RuntimeException('log is unwritable');
                }

                $GLOBALS['__bcc_orp']['logs'][] = [$message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                unset($message, $context);
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                unset($message, $context);
            }
        }
    }
}

namespace BCC\Core\Cron {
    if (!\class_exists(AsyncDispatcher::class, false)) {
        final class AsyncDispatcher
        {
            /** @param list<mixed> $args */
            public static function scheduleSingle(int $timestamp, string $hook, array $args = [], string $group = ''): void
            {
                if ($GLOBALS['__bcc_orp']['schedule_throws']) {
                    throw new \RuntimeException('scheduler unavailable');
                }

                $GLOBALS['__bcc_orp']['scheduled'][] = [$timestamp, $hook, $args, $group];
            }

            /** @param list<mixed> $args */
            public static function enqueueAsync(string $hook, array $args = [], string $group = ''): bool
            {
                $GLOBALS['__bcc_orp']['scheduled'][] = [0, $hook, $args, $group];

                return true;
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!\function_exists('BCC\Core\PeepSo\wp_update_post')) {
        /**
         * Faithful to the one behaviour F-542 depends on, verified against
         * wp-includes/post.php: `wp_update_post` calls `get_post()` FIRST and
         * returns 0 before touching a row when that answers null. It cannot
         * tell "no such post" from "the read failed", so neither can this.
         *
         * @param array<string, mixed> $postarr
         * @return int 0 = nothing was written
         */
        function wp_update_post($postarr, $wp_error = false): int
        {
            unset($wp_error);

            $state = &$GLOBALS['__bcc_orp'];
            $id    = (int) ($postarr['ID'] ?? 0);

            if ($state['trash_throws']) {
                // What a listener woken by the write looks like from here:
                // the pin is recorded (it was held) and then it blows up.
                $state['pins'][] = $_GET;
                throw new \RuntimeException('a listener threw during the trash');
            }

            // What the request looked like while the write ran — the pin.
            $state['pins'][]    = $_GET;
            $state['updates'][] = $postarr;

            if ($state['post_read_fails']) {
                // get_post() could not be trusted: 0, and NO row changes.
                return 0;
            }

            if (!isset($state['posts'][$id])) {
                // No such post. 0, and no row is created.
                return 0;
            }

            $state['posts'][$id] = (string) ($postarr['post_status'] ?? $state['posts'][$id]);

            return $id;
        }
    }
}

namespace {
    /**
     * A `$wpdb` answering the one SELECT `postStatusOrUnknown()` issues, with
     * the two failure modes ProbesReads actually distinguishes:
     *
     *   'error'    query() returns false AND last_error is set
     *   'preflush' a row count with NO last_error, while last_result still
     *              holds the PREVIOUS query's rows — the bail that a reader
     *              trusting get_results() + last_error would serve as real
     */
    final class BccOrpWpdb
    {
        public string $prefix     = 'wp_';
        public string $posts      = 'wp_posts';
        public string $last_error = '';
        /** @var list<object> */
        public array $last_result = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            $next = 0;

            return (string) preg_replace_callback(
                '/%[ds]/',
                static function (array $m) use (&$next, $args): string {
                    $value = $args[$next++] ?? '';

                    return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
                },
                $sql
            );
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $state = &$GLOBALS['__bcc_orp'];

            $this->last_error  = '';
            $this->last_result = [];

            if (stripos($sql, 'wp_posts') === false) {
                return 0;
            }

            $state['queries']++;
            $mode = array_shift($state['modes']) ?: 'ok';

            if ($mode === 'throw') {
                // A driver exception rather than a false return.
                throw new \RuntimeException('mysqli threw');
            }

            if ($mode === 'error') {
                $this->last_error = 'posts read failed';

                return false;
            }

            if ($mode === 'preflush' || $mode === 'preflush_trash') {
                // Stale rows naming an id this query never asked about, which
                // is what makes them recognisably not ours.
                //
                // ⚠⚠ `preflush_trash` is the SHARPER case: the stale row happens
                // to say 'trash'. Without the ID check the repair reads that as
                // "already handled" and walks away, abandoning a live orphan on
                // the strength of another query's row. With it, the answer is
                // UNKNOWN and the retry is queued.
                $this->last_result = [(object) [
                    'ID'          => 999999,
                    'post_status' => $mode === 'preflush_trash' ? 'trash' : 'publish',
                ]];

                return 1;
            }

            if (preg_match('/ID = (\d+)/', $sql, $m) !== 1) {
                return 0;
            }
            $id = (int) $m[1];

            if (!isset($state['posts'][$id])) {
                return 0;
            }

            $this->last_result = [(object) ['ID' => $id, 'post_status' => (string) $state['posts'][$id]]];

            return 1;
        }
    }
}
