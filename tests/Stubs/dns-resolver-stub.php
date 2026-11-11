<?php
/**
 * Switchable DNS-resolution stub for `SafeHttpClient`.
 *
 * ⚠⚠ HOW THIS WORKS, AND WHY IT IS SAFE FOR SIBLING TESTS.
 * `SafeHttpClient` lives in `namespace BCC\Core\Http` and calls
 * `gethostbyname()` / `dns_get_record()` **unqualified**. PHP's
 * namespace fallback resolves an unqualified function call to the
 * CURRENT namespace first and only then to the global one — so
 * defining `BCC\Core\Http\gethostbyname()` here shadows the real
 * function for that class, with no runkit, no process isolation and no
 * change to production code.
 *
 * ⚠ But a bare shadow would be process-global in effect: once defined
 * it intercepts every call from that namespace, including
 * `SafeHttpClientBatchTest`'s. So it is SWITCHABLE — disabled by
 * default, and when disabled it delegates to the real global function.
 * Sibling tests therefore behave exactly as before, and nothing needs
 * `#[RunTestsInSeparateProcesses]`.
 *
 * ⚠ `gethostbyname()` signals failure by returning THE HOSTNAME
 * UNCHANGED (not false), which is what `resolvePublicIp()` tests with
 * `$ipv4 !== $host`. The stub reproduces that rather than inventing a
 * falsy return, or the test would exercise a path production never
 * takes.
 *
 * Guarded so a second require in the same process is harmless.
 */

declare(strict_types=1);

namespace BCC\Core\Http\Tests {
    if (!class_exists(__NAMESPACE__ . '\\DnsStub', false)) {
        final class DnsStub
        {
            /** Interception off by default — siblings keep real DNS. */
            public static bool $enabled = false;

            /** IPv4 to return, or null to simulate "no A record". */
            public static ?string $ipv4 = null;

            /** @var list<array<string, string>> AAAA records to return. */
            public static array $aaaa = [];

            /** Every host asked about, in order. */
            public static array $lookups = [];

            /**
             * Virtual "now" the shadowed `time()` reports, or 0 for "use the
             * real clock" — the default, which keeps that shadow inert.
             */
            public static int $now = 0;

            public static function reset(): void
            {
                self::$enabled = false;
                self::$ipv4    = null;
                self::$aaaa    = [];
                self::$lookups = [];
                self::$now     = 0;
            }

            /**
             * Start the virtual clock at a fixed instant.
             *
             * A literal rather than `time()`: a test pinning TTL arithmetic
             * should not depend on when it ran.
             */
            public static function startClock(int $at = 1700000000): void
            {
                self::$now = $at;
            }

            /** Move the virtual clock forward. */
            public static function advance(int $seconds): void
            {
                if (self::$now > 0) {
                    self::$now += $seconds;
                }
            }

            /** Resolve to nothing public — the failure this suite is about. */
            public static function failAll(): void
            {
                self::$enabled = true;
                self::$ipv4    = null;
                self::$aaaa    = [];
            }

            public static function succeedWith(string $ip): void
            {
                self::$enabled = true;
                self::$ipv4    = $ip;
                self::$aaaa    = [];
            }
        }
    }
}

namespace BCC\Core\Http {

    use BCC\Core\Http\Tests\DnsStub;

    if (!function_exists(__NAMESPACE__ . '\\gethostbyname')) {
        /**
         * @param string $hostname
         * @return string the resolved IPv4, or $hostname unchanged on failure
         */
        function gethostbyname($hostname): string
        {
            if (!DnsStub::$enabled) {
                return \gethostbyname($hostname);
            }
            DnsStub::$lookups[] = (string) $hostname;

            // ⚠ The hostname BACK is how the real function reports
            // failure. Returning false here would be a shape production
            // never sees.
            return DnsStub::$ipv4 ?? (string) $hostname;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\dns_get_record')) {
        /**
         * @param string $hostname
         * @param int    $type
         * @return list<array<string, string>>
         */
        function dns_get_record($hostname, $type = DNS_ANY): array
        {
            if (!DnsStub::$enabled) {
                $real = \dns_get_record($hostname, $type);
                return is_array($real) ? $real : [];
            }
            return DnsStub::$aaaa;
        }
    }

    /**
     * Shadows `time()` for this namespace — the same namespace-fallback
     * mechanism as the two resolver shadows above.
     *
     * ⚠⚠ INERT UNLESS A TEST STARTS THE CLOCK. With `DnsStub::$now` at 0
     * this delegates to the real global `time()`, so the sibling suites that
     * load this file see real time and are untouched. That matters more here
     * than for the resolver shadows: `SafeHttpClient` calls `time()` on every
     * DNS cache read and write, so a clock that defaulted to a fixed instant
     * would silently freeze both cache TTLs for every test in the process.
     *
     * ⚠ A fake clock rather than a slow test. The TTLs are 60s and 10s, so
     * asserting expiry against the wall clock would mean a test that really
     * waits a minute — and a test that waits a minute is a test somebody
     * shortens later.
     */
    if (!function_exists(__NAMESPACE__ . '\\time')) {
        function time(): int
        {
            return DnsStub::$now > 0 ? DnsStub::$now : \time();
        }
    }
}
