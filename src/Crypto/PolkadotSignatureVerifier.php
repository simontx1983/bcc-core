<?php
/**
 * Polkadot / Substrate Signature Verifier
 *
 * Polkadot wallets sign with sr25519 (default), ed25519, or ecdsa.
 * PHP has no native sr25519 / schnorrkel implementation — there's no
 * equivalent of libsodium for the Ristretto25519 + Merlin primitives
 * the Polkadot ecosystem uses, and porting one is a security risk we
 * are not willing to own.
 *
 * Verification is therefore DELEGATED to the bcc-frontend Next.js app,
 * which runs `@polkadot/util-crypto` server-side. PHP POSTs the
 * (chain_type, message, signature, address) tuple to an internal
 * authenticated route; the route returns `{ isValid: bool, crypto: ... }`.
 *
 * Trust posture:
 *  - The Next.js app already holds NextAuth signing keys and is part
 *    of the same trust domain as bcc-trust. Adding signature
 *    verification there is consistent with the existing model — we
 *    are not extending trust to a third party.
 *  - The internal route is authenticated via the `X-Bcc-Internal`
 *    header carrying `BCC_INTERNAL_VERIFY_SECRET` (same recipe as
 *    `BCC_INTERNAL_CRON_SECRET` for the Vercel cron relay). Constant-
 *    time compare on the receiver.
 *  - The destination URL is operator-pinned via `BCC_FRONTEND_ORIGIN`
 *    (the canonical-mint value — first entry of the comma-separated
 *    list). Because the URL is operator-pinned (not user input), we
 *    intentionally bypass {@see \BCC\Core\Http\SafeHttpClient}, which
 *    would block private/loopback IPs and prevent the localhost
 *    development case. SSRF protection does not apply when the
 *    destination is not user-influenced.
 *
 * Failure posture:
 *  - Every failure mode (missing config, transport error, non-200,
 *    malformed body) returns false AND records a
 *    `DegradationMetrics::record('polkadot_verify', $event)` row.
 *    Sustained activation = Polkadot wallet linking is broken; the
 *    operator should investigate before users notice.
 *
 * @package BCC\Core\Crypto
 */

declare(strict_types=1);

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class PolkadotSignatureVerifier
{
    /** Internal route relative path. */
    private const ROUTE_PATH = '/api/internal/verify-wallet-signature';

    /** Verify request timeout — verify() at the Node side is <50ms. */
    private const TIMEOUT_SECONDS = 5;

    /** Max message length — mirrors EthSignatureVerifier's DoS cap. */
    private const MAX_MESSAGE_LENGTH = 1024;

    /**
     * Verify a Polkadot wallet signature by delegating to the
     * bcc-frontend internal verify route.
     *
     * @param string $message   UTF-8 challenge string that was signed
     * @param string $signature Hex-encoded signature (with or without 0x)
     * @param string $address   SS58 address (mainnet prefix 0; Substrate
     *                          chain-specific prefixes also accepted by
     *                          the upstream verifier)
     * @return bool             True iff the signature verifies under
     *                          at least one of sr25519 / ed25519 / ecdsa.
     */
    public static function verify(string $message, string $signature, string $address): bool
    {
        if ($message === '' || $signature === '' || $address === '') {
            return false;
        }

        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return false;
        }

        $secret = self::secret();
        if ($secret === '') {
            self::recordMetric('secret_missing');
            self::log('error', 'BCC_INTERNAL_VERIFY_SECRET not configured');
            return false;
        }

        $url = self::routeUrl();
        if ($url === '') {
            self::recordMetric('frontend_url_missing');
            self::log('error', 'BCC_FRONTEND_ORIGIN not configured — cannot reach verify route');
            return false;
        }

        $payload = wp_json_encode([
            'chain_type' => 'polkadot',
            'message'    => $message,
            'signature'  => $signature,
            'address'    => $address,
        ]);
        if (!is_string($payload)) {
            // Defensive — json_encode of the above shape cannot realistically fail,
            // but a non-string return here would silently corrupt the request body.
            return false;
        }

        $response = wp_remote_post($url, [
            'timeout'     => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'blocking'    => true,
            'sslverify'   => true,
            'headers'     => [
                'Content-Type'   => 'application/json',
                'Accept'         => 'application/json',
                'X-Bcc-Internal' => $secret,
            ],
            'body'        => $payload,
        ]);

        if (is_wp_error($response)) {
            self::recordMetric('route_unreachable');
            self::log('warning', 'verify route transport error', [
                'detail' => $response->get_error_message(),
            ]);
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            self::recordMetric('route_error_status');
            self::log('warning', 'verify route non-200', ['status' => $code]);
            return false;
        }

        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_key_exists('isValid', $decoded)) {
            self::recordMetric('route_malformed_body');
            self::log('error', 'verify route returned malformed body', [
                'body_excerpt' => substr($body, 0, 200),
            ]);
            return false;
        }

        return (bool) $decoded['isValid'];
    }

    private static function secret(): string
    {
        return defined('BCC_INTERNAL_VERIFY_SECRET')
            ? (string) constant('BCC_INTERNAL_VERIFY_SECRET')
            : '';
    }

    /**
     * Resolve the verify-route URL from the canonical frontend origin.
     *
     * BCC_FRONTEND_ORIGIN may be a single value or a comma-separated
     * allowlist (multi-tenant case — one backend serves prod + staging).
     * The first entry is the canonical mint value per JwtToken's
     * audienceAllowlist contract; we mirror that convention here so the
     * internal call goes to the same canonical frontend.
     */
    private static function routeUrl(): string
    {
        if (!defined('BCC_FRONTEND_ORIGIN')) {
            return '';
        }
        $raw = (string) constant('BCC_FRONTEND_ORIGIN');
        if ($raw === '') {
            return '';
        }
        $first = trim(explode(',', $raw)[0]);
        if ($first === '') {
            return '';
        }
        return rtrim($first, '/') . self::ROUTE_PATH;
    }

    /**
     * Record a degradation-metric event. Wrapped in class_exists() so
     * that bcc-core can be activated independently of the observability
     * layer (matches the rest of the bcc-core verifier pattern).
     *
     * @param string $event One of the events declared in
     *                      bcc-core/bcc-core.php's `polkadot_verify`
     *                      subsystem map.
     */
    private static function recordMetric(string $event): void
    {
        if (class_exists('\\BCC\\Core\\Observability\\DegradationMetrics')) {
            \BCC\Core\Observability\DegradationMetrics::record('polkadot_verify', $event);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        if (!class_exists('\\BCC\\Core\\Log\\Logger')) {
            return;
        }
        $logger = '\\BCC\\Core\\Log\\Logger';
        $prefixed = '[bcc-core] PolkadotVerifier: ' . $message;
        if ($level === 'error') {
            $logger::error($prefixed, $context);
        } else {
            $logger::warning($prefixed, $context);
        }
    }
}
