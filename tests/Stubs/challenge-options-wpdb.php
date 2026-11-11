<?php

/**
 * An in-memory wp_options table behind a $wpdb double, for the wallet
 * challenge tests (ChallengeRepository's direct-SQL store / peek /
 * peekByPrefix / consume), plus a recording AdvisoryLock.
 *
 * Loaded ONLY from inside #[RunTestsInSeparateProcesses] subprocesses and
 * BEFORE anything autoloads BCC\Core\DB\AdvisoryLock, so this double wins
 * and the main PHPUnit process never sees these global definitions.
 *
 * prepare() returns an encoded (sql, args) pair that the query methods
 * decode, so the double matches on the real SQL shape and real arguments.
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

namespace {

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (!class_exists('BccTestOptionsWpdb', false)) {
        final class BccTestOptionsWpdb
        {
            public string $options = 'wp_options';

            /** @var array<string, string> option_name => option_value */
            public array $rows = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return 'PREP:' . json_encode([$sql, $args]);
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
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

            /** @return string|null */
            public function get_var(string $q)
            {
                [$sql, $args] = self::decode($q);
                if (str_contains($sql, 'INNODB_TRX')) {
                    return null;
                }
                if (str_contains($sql, 'SELECT option_value') && str_contains($sql, 'option_name = %s')) {
                    return $this->rows[(string) $args[0]] ?? null;
                }
                throw new \RuntimeException('unexpected get_var: ' . $sql);
            }

            /** @return list<array{option_name: string, option_value: string}> */
            public function get_results(string $q, string $output = 'OBJECT'): array
            {
                [$sql, $args] = self::decode($q);
                if (!str_contains($sql, 'option_name LIKE %s')) {
                    throw new \RuntimeException('unexpected get_results: ' . $sql);
                }
                $like = (string) $args[0];
                if (!str_ends_with($like, '%')) {
                    throw new \RuntimeException('expected a prefix LIKE');
                }
                $prefix = stripcslashes(substr($like, 0, -1));
                $names  = array_filter(array_keys($this->rows), static fn(string $n): bool => str_starts_with($n, $prefix));
                sort($names);
                $out = [];
                foreach (array_slice($names, 0, (int) $args[1]) as $n) {
                    $out[] = ['option_name' => $n, 'option_value' => $this->rows[$n]];
                }
                return $out;
            }

            /** @return int|bool */
            public function query(string $q)
            {
                [$sql, $args] = self::decode($q);
                if (in_array(trim($sql), ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
                    return true;
                }
                if (str_starts_with(trim($sql), 'INSERT INTO')) {
                    $this->rows[(string) $args[0]] = (string) $args[1];
                    return 1;
                }
                if (str_starts_with(trim($sql), 'DELETE FROM')) {
                    $n = 0;
                    foreach ($args as $name) {
                        if (isset($this->rows[(string) $name])) {
                            unset($this->rows[(string) $name]);
                            $n++;
                        }
                    }
                    return $n;
                }
                throw new \RuntimeException('unexpected query: ' . $sql);
            }
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
}
