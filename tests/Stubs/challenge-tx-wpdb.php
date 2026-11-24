<?php

/**
 * An in-memory wp_options table with TRANSACTION semantics behind a $wpdb
 * double, for ChallengeRepository's in-transaction primitives
 * (transactionState / consumeInTransaction / update) and the validator memo
 * challenge built on them. Plus recording doubles for AdvisoryLock and
 * Logger, and transient functions for DegradationMetrics.
 *
 * Loaded ONLY from #[RunTestsInSeparateProcesses] tests, BEFORE anything
 * autoloads AdvisoryLock or Logger, so these doubles win in that process.
 *
 * What it models, as MySQL 8 and MariaDB do (checked against both):
 *   - START TRANSACTION / BEGIN open a transaction; COMMIT ends it;
 *     ROLLBACK restores the rows as they were when it opened.
 *   - SAVEPOINT outside a transaction succeeds but leaves nothing behind,
 *     so RELEASE SAVEPOINT then fails with
 *     "SAVEPOINT <name> does not exist" (error 1305). Inside a transaction
 *     the release succeeds.
 *   - SELECT … FOR UPDATE through query() fills last_result (what
 *     ProbesReads reads).
 *
 * Faults ($faults): read_fails, pre_flush_bail (query() returns false with
 * NO last_error and leaves last_result stale), delete_zero, update_fails,
 * savepoint_fails, release_other_error.
 */

declare(strict_types=1);

namespace BCC\Core\DB {
    if (!class_exists(AdvisoryLock::class, false)) {
        class AdvisoryLock
        {
            /** @var list<string> */
            public static array $log = [];
            public static bool $deny = false;

            public static function acquire(string $key, int $timeout = 0): bool
            {
                self::$log[] = 'acquire:' . $key;
                return !self::$deny;
            }

            public static function release(string $key): void
            {
                self::$log[] = 'release:' . $key;
            }
        }
    }
}

namespace BCC\Core\Wallet {
    // A controllable clock for the Wallet namespace only: an unqualified
    // time() there resolves here first. $GLOBALS['__bcc_clock'] unset =
    // the real time.
    if (!function_exists(__NAMESPACE__ . '\time')) {
        function time(): int
        {
            return isset($GLOBALS['__bcc_clock']) ? (int) $GLOBALS['__bcc_clock'] : \time();
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        class Logger
        {
            /** @var list<array{string, string, array<string, mixed>}> */
            public static array $log = [];

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                self::$log[] = ['WARNING', $message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                self::$log[] = ['ERROR', $message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                self::$log[] = ['INFO', $message, $context];
            }
        }
    }
}

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    final class BccTxOptionsWpdb
    {
        public string $options = 'wp_options';
        public string $last_error = '';
        /** @var mixed */
        public $last_result = [];

        /** @var array<string, string> option_name => option_value */
        public array $rows = [];
        public bool $inTransaction = false;
        /** @var array<string, string> */
        private array $snapshot = [];
        /** @var array<string, true> */
        private array $savepoints = [];
        public bool $suppress = false;

        /** @var array<string, true> */
        public array $faults = [];
        /** @var list<string> every statement, decoded */
        public array $log = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            return 'PREP:' . json_encode([$sql, $args]);
        }

        public function suppress_errors(bool $suppress = true): bool
        {
            $was            = $this->suppress;
            $this->suppress = $suppress;
            return $was;
        }

        /** @return array{0: string, 1: list<mixed>} */
        private static function decode(string $q): array
        {
            if (!str_starts_with($q, 'PREP:')) {
                return [$q, []];
            }
            /** @var array{0: string, 1: list<mixed>} $d */
            $d = json_decode(substr($q, 5), true);
            return $d;
        }

        private static function flat(string $sql): string
        {
            return trim((string) preg_replace('/\s+/', ' ', $sql));
        }

        /** @return string|null */
        public function get_var(string $q)
        {
            [$sql, $args] = self::decode($q);
            $this->log[]  = self::flat($sql);
            if (str_contains($sql, 'INNODB_TRX')) {
                return null;
            }
            if (str_contains($sql, 'SELECT option_value') && str_contains($sql, 'option_name = %s')) {
                return $this->rows[(string) $args[0]] ?? null;
            }
            throw new \RuntimeException('unexpected get_var: ' . $sql);
        }

        /** @return int|bool */
        public function query(string $q)
        {
            [$sql, $args] = self::decode($q);
            $flat         = self::flat($sql);
            $this->log[]  = $flat;

            if ($flat === 'START TRANSACTION' || $flat === 'BEGIN') {
                $this->last_error    = '';
                $this->inTransaction = true;
                $this->snapshot      = $this->rows;
                $this->savepoints    = [];
                return true;
            }
            if ($flat === 'COMMIT') {
                $this->inTransaction = false;
                $this->savepoints    = [];
                return true;
            }
            if ($flat === 'ROLLBACK') {
                if ($this->inTransaction) {
                    $this->rows = $this->snapshot;
                }
                $this->inTransaction = false;
                $this->savepoints    = [];
                return true;
            }
            if (str_starts_with($flat, 'SAVEPOINT ')) {
                if (isset($this->faults['savepoint_fails'])) {
                    $this->last_error = 'MySQL server has gone away';
                    return false;
                }
                $this->last_error = '';
                if ($this->inTransaction) {
                    $this->savepoints[substr($flat, 10)] = true;
                }
                return 0;
            }
            if (str_starts_with($flat, 'RELEASE SAVEPOINT ')) {
                $name = substr($flat, 18);
                if (isset($this->faults['release_other_error'])) {
                    $this->last_error = 'Lost connection to MySQL server during query';
                    return false;
                }
                if (isset($this->savepoints[$name])) {
                    unset($this->savepoints[$name]);
                    $this->last_error = '';
                    return 0;
                }
                $this->last_error = 'SAVEPOINT ' . $name . ' does not exist';
                return false;
            }
            if (str_starts_with($flat, 'SELECT option_value') && str_contains($flat, 'FOR UPDATE')) {
                if (isset($this->faults['pre_flush_bail'])) {
                    return false; // no last_error, last_result left as it was
                }
                if (isset($this->faults['read_fails'])) {
                    $this->last_error  = 'Deadlock found when trying to get lock';
                    $this->last_result = [];
                    return false;
                }
                $this->last_error  = '';
                $name              = (string) $args[0];
                $this->last_result = isset($this->rows[$name]) ? [(object) ['option_value' => $this->rows[$name]]] : [];
                return count($this->last_result);
            }
            if (str_starts_with($flat, 'INSERT INTO')) {
                $this->rows[(string) $args[0]] = (string) $args[1];
                return 1;
            }
            if (str_starts_with($flat, 'UPDATE')) {
                if (isset($this->faults['update_fails'])) {
                    $this->last_error = 'Lock wait timeout exceeded';
                    return false;
                }
                $name = (string) $args[1];
                if (!isset($this->rows[$name])) {
                    return 0;
                }
                $this->rows[$name] = (string) $args[0];
                return 1;
            }
            if (str_starts_with($flat, 'DELETE FROM')) {
                if (isset($this->faults['delete_zero'])) {
                    return 0;
                }
                $n = 0;
                foreach ($args as $name) {
                    if (isset($this->rows[(string) $name])) {
                        unset($this->rows[(string) $name]);
                        $n++;
                    }
                }
                return $n;
            }
            throw new \RuntimeException('unexpected query: ' . $flat);
        }
    }

    if (!function_exists('maybe_serialize')) {
        /** @param mixed $data */
        function maybe_serialize($data): string
        {
            return is_array($data) || is_object($data) ? serialize($data) : (string) $data;
        }
    }

    if (!function_exists('maybe_unserialize')) {
        /** @return mixed */
        function maybe_unserialize(string $data)
        {
            $v = @unserialize($data);
            return $v === false && $data !== 'b:0;' ? $data : $v;
        }
    }

    if (!function_exists('wp_cache_delete')) {
        /** @param int|string $key */
        function wp_cache_delete($key, string $group = ''): bool
        {
            return true;
        }
    }

    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return $GLOBALS['__bcc_transients'][$key] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            $GLOBALS['__bcc_transients'][$key] = $value;
            return true;
        }
    }
}
