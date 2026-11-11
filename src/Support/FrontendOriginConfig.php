<?php
/**
 * FrontendOriginConfig — bcc-core's reader for the canonical frontend origin.
 *
 * BCC_FRONTEND_ORIGIN is a comma-separated allowlist (one backend can serve
 * prod + staging), and may hold `regex:` entries that are Origin-header
 * matching rules, not URL bases. The FIRST EXACT entry is canonical: it mints
 * the JWT `aud`, and it is the public site a person is on.
 *
 * bcc-trust has its own FrontendOrigin for its consumers; this is bcc-core's
 * single copy because the dependency runs trust -> core, never the reverse.
 * Consumers: PolkadotSignatureVerifier (the verify-route base URL) and
 * WalletChallenge (the host named in the signed message).
 *
 * @package BCC\Core\Support
 */

declare(strict_types=1);

namespace BCC\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class FrontendOriginConfig
{
    /**
     * The first exact (non-`regex:`) entry of BCC_FRONTEND_ORIGIN, without a
     * trailing slash; null when the constant is undefined or has no exact
     * entry.
     */
    public static function canonical(): ?string
    {
        if (!defined('BCC_FRONTEND_ORIGIN')) {
            return null;
        }
        foreach (explode(',', (string) constant('BCC_FRONTEND_ORIGIN')) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || str_starts_with($entry, 'regex:')) {
                continue;
            }
            return rtrim($entry, '/');
        }
        return null;
    }

    /**
     * Lower-cased host[:port] of the public site: the canonical frontend
     * origin, else home_url() (the public front door under the headless
     * split). '' when neither yields a host.
     */
    public static function publicHost(): string
    {
        $origin = self::canonical() ?? (function_exists('home_url') ? (string) home_url() : '');
        $parts  = parse_url($origin);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = strtolower((string) $parts['host']);
        return isset($parts['port']) ? $host . ':' . (int) $parts['port'] : $host;
    }
}
