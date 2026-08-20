<?php

/**
 * WP_HOME / WP_SITEURL shims for the headless-split tests.
 *
 * The production code reads the two origins through home_url() and
 * site_url(); these let a test set them per-case. Defaults reproduce the
 * live split (apex = Next.js front door, cms.* = WordPress).
 *
 * Loaded ONLY from inside #[RunTestsInSeparateProcesses] subprocesses,
 * matching object-cache-stubs.php, so the main PHPUnit process never
 * sees these global definitions.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('BccTestSiteUrls', false)) {
        /** Mutable stand-in for WP_HOME / WP_SITEURL. */
        final class BccTestSiteUrls
        {
            public static string $home = 'https://bluecollarcrypto.io';
            public static string $site = 'https://cms.bluecollarcrypto.io';

            /** Collapse the split — the shape of a non-headless install. */
            public static function unsplit(string $both = 'https://bluecollarcrypto.io'): void
            {
                self::$home = $both;
                self::$site = $both;
            }

            public static function reset(): void
            {
                self::$home = 'https://bluecollarcrypto.io';
                self::$site = 'https://cms.bluecollarcrypto.io';
            }
        }
    }

    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return \BccTestSiteUrls::$home . $path;
        }
    }

    if (!function_exists('site_url')) {
        function site_url(string $path = ''): string
        {
            return \BccTestSiteUrls::$site . $path;
        }
    }

    if (!function_exists('wp_parse_url')) {
        /** @return array<string, int|string>|string|int|false|null */
        function wp_parse_url(string $url, int $component = -1)
        {
            return parse_url($url, $component);
        }
    }
}
