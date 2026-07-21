<?php

/**
 * Persistent object-cache stubs — simulate the CROSS-PROCESS read
 * semantics of a real external backend (Redis/Predis, memcached).
 *
 * The key behavior under test: WordPress persistent-cache drop-ins
 * serialize values with maybe_serialize(), and maybe_serialize(0) is
 * the raw string "0" — so an integer written by one PHP process comes
 * back as a NUMERIC STRING in every other process. The in-process
 * runtime cache masks this (same-request reads stay typed), which is
 * exactly why strict `is_int(wp_cache_get(...))` checks pass in
 * single-process tests but break in production.
 *
 * These shims reproduce the cross-process view: `wp_cache_get`
 * stringifies integers on the way out (toggle via
 * BccTestPersistentCache::$stringifyIntsOnGet). `wp_cache_incr`
 * follows Redis semantics: numeric value +1 (creating the key when
 * missing), stored numerically.
 *
 * Loaded ONLY from inside #[RunTestsInSeparateProcesses] subprocesses
 * (same isolation strategy as nft-indexer-stubs.php) so the main
 * PHPUnit process never sees these global definitions.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('BccTestPersistentCache', false)) {
        /** In-memory persistent-cache fake shared by the shims below. */
        final class BccTestPersistentCache
        {
            /** @var array<string, mixed> */
            public static array $store = [];

            /** Simulate the maybe_serialize int→string round-trip on reads. */
            public static bool $stringifyIntsOnGet = true;

            public static function reset(): void
            {
                self::$store = [];
                self::$stringifyIntsOnGet = true;
            }

            /** Raw in-store value — lets tests assert nothing reset it. */
            public static function raw(string $key, string $group): mixed
            {
                return self::$store[$group . ':' . $key] ?? null;
            }
        }
    }

    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            $k = $group . ':' . $key;
            if (!array_key_exists($k, \BccTestPersistentCache::$store)) {
                return false;
            }
            $value = \BccTestPersistentCache::$store[$k];
            if (is_int($value) && \BccTestPersistentCache::$stringifyIntsOnGet) {
                return (string) $value;
            }
            return $value;
        }
    }

    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            \BccTestPersistentCache::$store[$group . ':' . $key] = $value;
            return true;
        }
    }

    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete(string $key, string $group = ''): bool
        {
            unset(\BccTestPersistentCache::$store[$group . ':' . $key]);
            return true;
        }
    }

    if (!function_exists('wp_cache_incr')) {
        /** @return int|false */
        function wp_cache_incr(string $key, int $offset = 1, string $group = '')
        {
            $k       = $group . ':' . $key;
            $current = \BccTestPersistentCache::$store[$k] ?? null;
            // Redis INCR semantics: missing key initializes at 0 before
            // the increment; numeric strings increment fine.
            $base = is_numeric($current) ? (int) $current : 0;
            $next = $base + $offset;
            \BccTestPersistentCache::$store[$k] = $next;
            return $next;
        }
    }
}
