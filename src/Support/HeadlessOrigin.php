<?php
/**
 * HeadlessOrigin — one rule for repairing URLs that point at the public
 * front door when they need to point at WordPress.
 *
 * This install runs the headless split: WP_SITEURL is the WordPress
 * origin (cms.*) while WP_HOME stays the public front door, served by
 * the Next.js app. Anything WordPress derives from `home_url()` therefore
 * addresses the frontend — which serves no `/wp-json` and no
 * `/wp-content`, and answers 403.
 *
 * Two independent consumers hit that same wall, which is why the rule
 * lives here rather than inline (§11):
 *
 *   1. `rest_url()` — WordPress builds every REST URL from home_url(),
 *      so the /wp-json index, the <head> discovery link, wp-admin's own
 *      REST calls and every OAuth callback pointed at the frontend.
 *      Fixed by the `rest_url` filter in bcc-core.php, which delegates
 *      here.
 *   2. PeepSo media URLs — {@see \BCC\Core\PeepSo\PeepSoMediaCache}.
 *      PeepSo persists a generated avatar's finished URL into usermeta
 *      and returns that string verbatim forever after, so URLs minted
 *      before the cutover stayed frozen on the apex.
 *
 * Deliberately a no-op when the two origins already match, so local dev
 * and any non-split install are untouched and this needs no environment
 * gate.
 *
 * @package BCC\Core\Support
 * @since 2026-08-20 (extracted from the bcc-core.php rest_url filter)
 */

declare(strict_types=1);

namespace BCC\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class HeadlessOrigin
{
    /**
     * Characters that may legally follow an origin in a URL. Used as a
     * boundary check so a prefix match cannot straddle a hostname.
     */
    private const ORIGIN_BOUNDARY = ['/', '?', '#'];

    /**
     * Rebase $url from the public front door onto the WordPress origin.
     *
     * Returns $url untouched when it is not on the front-door origin —
     * so third-party hosts (Gravatar, a CDN) and already-correct URLs
     * pass through unchanged — and when the two origins match.
     */
    public static function toWordPress(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $home = self::originOf((string) home_url());
        $site = self::originOf((string) site_url());

        if ($home === null || $site === null || $home === $site) {
            return $url;
        }

        if (strncasecmp($url, $home, strlen($home)) !== 0) {
            return $url;
        }

        // The prefix must end at an origin boundary. Without this,
        // `https://example.com.evil.test/x` matches the prefix
        // `https://example.com` and would be rewritten into
        // `https://cms.example.com.evil.test/x` — a host we do not own,
        // re-pointed and handed back as if it were ours. Bare `''`
        // (the origin alone) is legitimate.
        $rest = substr($url, strlen($home));
        if ($rest !== '' && !in_array($rest[0], self::ORIGIN_BOUNDARY, true)) {
            return $url;
        }

        return $site . $rest;
    }

    /**
     * Normalized `scheme://host[:port]` for $candidate, or null when it
     * carries no usable scheme + host.
     *
     * Scheme and host are lowercased (both are case-insensitive per
     * RFC 3986) so comparisons are stable; the port is left as parsed.
     */
    public static function originOf(string $candidate): ?string
    {
        $parts = wp_parse_url($candidate);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? $parts['scheme'] : '';
        $host   = isset($parts['host'])   && is_string($parts['host'])   ? $parts['host']   : '';
        if ($scheme === '' || $host === '') {
            return null;
        }

        $port = isset($parts['port']) && is_int($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($scheme . '://' . $host) . $port;
    }
}
