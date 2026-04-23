<?php

namespace BCC\Core\Http;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hardened drop-in replacement for `wp_remote_get` / `wp_remote_post`.
 *
 * Adds SSRF protections that the WordPress HTTP API does NOT provide on its
 * own:
 *
 *   1. Block private / reserved IPv4 + IPv6 ranges (RFC1918, link-local,
 *      loopback, ULA, etc.).
 *   2. Block well-known cloud metadata hostnames before DNS resolution
 *      (defence against `169.254.169.254` reached via DNS rebinding).
 *   3. Pin the resolved IP via `CURLOPT_RESOLVE` so a hostile DNS response
 *      between validation and connection cannot redirect cURL to a private
 *      address (TOCTOU/rebinding defence).
 *   4. Disable HTTP redirects by default — every redirect hop could re-target
 *      a private IP and bypass the pinning. Callers that need redirect
 *      following can opt in by passing `redirection: N` explicitly.
 *
 * The return shape is identical to `wp_remote_*`:
 *   - `array{headers: …, body: string, response: ['code'=>int, 'message'=>string], …}`
 *     on a successful HTTP exchange (any status code), OR
 *   - `\WP_Error` on a transport error or SSRF block.
 *
 * SSRF blocks are returned as `WP_Error` with codes `ssrf_blocked`,
 * `ssrf_invalid_url`, `ssrf_invalid_scheme` so callers can distinguish them
 * from regular network failures.
 *
 * NOT a retry layer. NOT a circuit breaker. NOT a rate limiter. Compose
 * those on top — see `BCC\Onchain\Support\ApiRetry` for an example wrapper
 * that adds retry + circuit-breaker + budget tracking around this client.
 */
final class SafeHttpClient
{
    /**
     * Hostnames blocked outright before DNS resolution.
     *
     * The IP literal path also rejects link-local (169.254/16) via
     * FILTER_FLAG_NO_RES_RANGE, which covers the numeric forms of every
     * cloud metadata service — but hostnames are cheap to short-circuit
     * before we pay for DNS, and catch DNS-rebinding setups that route a
     * normal-looking hostname to 169.254.169.254 at connect time.
     */
    private const BLOCKED_HOSTS = [
        'metadata.google.internal',
        'metadata.google.com',
        'metadata',                   // AWS IMDSv1/IMDSv2 short form
        'metadata.aws.internal',
        'metadata.ec2.internal',
        'metadata.azure.com',
        'metadata.packet.net',        // Equinix Metal
        'metadata.internal.cloudapp.net',
        'metadata.local',             // DigitalOcean
        'opc.oraclecloud.com',
    ];

    /** Maximum response body size (bytes) — caps memory footprint per call. */
    private const DEFAULT_MAX_RESPONSE_BYTES = 5 * 1024 * 1024; // 5 MiB

    /** DNS resolution cache TTL (seconds, request-local only). */
    private const DNS_CACHE_TTL = 60;

    /** Pinned DNS entries: host → "host:port:ip" for CURLOPT_RESOLVE. */
    /** @var array<string, string> */
    private static array $pinnedResolves = [];

    /**
     * Per-request DNS resolution cache keyed by host → ['ip' => string, 'expires' => int].
     * Reset on every request because PHP-FPM static lifetime would otherwise
     * outlive short-TTL records.
     *
     * @var array<string, array{ip: string, expires: int}>
     */
    private static array $dnsCache = [];

    /** Whether the http_api_curl hook has been registered (process-once). */
    private static bool $hookRegistered = false;

    /** Whether the use_streams_transport filter has been added (process-once). */
    private static bool $streamsFilterAdded = false;

    /**
     * SSRF-hardened wrapper around `wp_remote_get`.
     *
     * @param string               $url
     * @param array<string, mixed> $args wp_remote_get args (headers, timeout, etc.).
     *                                   `redirection` defaults to 0; pass an
     *                                   explicit value to override.
     * @return array<string, mixed>|\WP_Error
     */
    public static function get(string $url, array $args = [])
    {
        $secured = self::prepareArgs($url, $args);
        if ($secured instanceof \WP_Error) {
            return $secured;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        try {
            return wp_remote_get($url, $secured);
        } finally {
            // Clear any pin we registered for this host so a later exception,
            // redirect, or retry path cannot leak a stale IP binding into an
            // unrelated cURL call on the same long-lived PHP-FPM worker.
            self::clearPin($host);
        }
    }

    /**
     * SSRF-hardened wrapper around `wp_remote_post`.
     *
     * @param string               $url
     * @param array<string, mixed> $args wp_remote_post args (headers, body, etc.).
     *                                   `redirection` defaults to 0; pass an
     *                                   explicit value to override.
     * @return array<string, mixed>|\WP_Error
     */
    public static function post(string $url, array $args = [])
    {
        $secured = self::prepareArgs($url, $args);
        if ($secured instanceof \WP_Error) {
            return $secured;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        try {
            return wp_remote_post($url, $secured);
        } finally {
            self::clearPin($host);
        }
    }

    /**
     * Remove a pinned DNS entry for a host after the request completes.
     * No-op when no pin was registered for the host.
     */
    private static function clearPin(string $host): void
    {
        if ($host !== '' && isset(self::$pinnedResolves[$host])) {
            unset(self::$pinnedResolves[$host]);
        }
    }

    /**
     * Apply SSRF validation + DNS pinning + safe defaults to a request args
     * array. Returns the modified args on success or a `WP_Error` on block.
     *
     * Exposed for advanced callers (e.g. `BCC\Onchain\Support\ApiRetry`'s
     * retry loop) that need to wrap the secured dispatch in their own
     * orchestration. Direct callers should use `get()` / `post()` instead.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public static function prepareArgs(string $url, array $args)
    {
        $pinResult = self::validateAndPinUrl($url);
        if ($pinResult instanceof \WP_Error) {
            return $pinResult;
        }

        if ($pinResult !== null) {
            $args = self::injectCurlResolve($args, $pinResult['host'], $pinResult['port'], $pinResult['ip']);
        }

        // Default-deny redirects (each hop could target a private IP and
        // bypass the pinning). Callers that need redirects must opt in by
        // passing `redirection` explicitly — null-coalesce honours that.
        $args['redirection'] = $args['redirection'] ?? 0;

        // Enforce a tight default timeout. A hostile endpoint (or its DNS)
        // can hold a PHP worker hostage; 3s is enough for any legitimate
        // external call the BCC ecosystem makes and caps the worker-pool
        // exhaustion surface. Callers that need longer must opt in, and
        // we still refuse anything above 30s.
        if (!isset($args['timeout']) || (float) $args['timeout'] <= 0) {
            $args['timeout'] = 3;
        } else {
            $args['timeout'] = min((float) $args['timeout'], 30.0);
        }

        // Cap response body size unless the caller explicitly set it. A
        // malicious public endpoint can return multi-GB responses and OOM
        // a PHP worker; wp_remote_* honours `limit_response_size` to short-
        // circuit the transfer once the cap is reached.
        if (!isset($args['limit_response_size']) || (int) $args['limit_response_size'] <= 0) {
            $args['limit_response_size'] = self::DEFAULT_MAX_RESPONSE_BYTES;
        }

        // Force cURL transport. CURLOPT_RESOLVE pinning is a cURL-only
        // feature — if WordPress falls back to Streams (no libcurl,
        // or a filter forcing it), the pin is silently dropped and
        // a TOCTOU DNS-rebinding window reopens. Reject the request
        // rather than send it insecurely.
        if (!function_exists('curl_init')) {
            return new \WP_Error(
                'ssrf_no_curl',
                'SafeHttpClient requires the PHP cURL extension; Streams transport cannot enforce DNS pinning.'
            );
        }
        // Register the streams-disable filter once per worker. WordPress
        // dedupes by callback so re-adding is functionally idempotent, but
        // gating avoids a per-call apply_filters bookkeeping cycle on hot
        // outbound paths.
        if (!self::$streamsFilterAdded) {
            add_filter('use_streams_transport', '__return_false');
            self::$streamsFilterAdded = true;
        }

        return $args;
    }

    /**
     * Validate a URL and resolve its IP for CURLOPT_RESOLVE pinning.
     *
     * Every URL — including hardcoded ones — is validated. No safe-host
     * shortcuts: attackers can poison DNS for any domain.
     *
     * Returns:
     *   - `WP_Error` if the URL is blocked (private IP, invalid scheme, etc.)
     *   - `null` if the host is already an IP literal (no pinning needed)
     *   - `array{host, port, ip}` for hostname-based URLs so the caller can pin
     *
     * @return \WP_Error|array{host: string, port: int, ip: string}|null
     */
    private static function validateAndPinUrl(string $url)
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return new \WP_Error('ssrf_invalid_url', 'Invalid URL: missing host');
        }

        $host   = $parsed['host'];
        $scheme = $parsed['scheme'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true)) {
            return new \WP_Error('ssrf_invalid_scheme', 'Only HTTP(S) URLs are allowed');
        }

        // Block cloud metadata endpoints by hostname before DNS resolution.
        if (in_array(strtolower($host), self::BLOCKED_HOSTS, true)) {
            return new \WP_Error('ssrf_blocked', 'Blocked request to cloud metadata endpoint');
        }

        // If host is already an IP literal, validate it directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return new \WP_Error('ssrf_blocked', "Blocked request to private/reserved IP: {$host}");
            }
            return null; // IP literal — no DNS to pin.
        }

        $port = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));

        // Serve from per-request cache when fresh. A PHP-FPM worker
        // processing a burst of calls to the same host would otherwise
        // block on the OS resolver on every call, and hostile upstreams
        // (or merely slow NXDOMAINs) become a worker-exhaustion vector.
        if (isset(self::$dnsCache[$host]) && self::$dnsCache[$host]['expires'] > time()) {
            return ['host' => $host, 'port' => $port, 'ip' => self::$dnsCache[$host]['ip']];
        }

        $pinnedIp = self::resolvePublicIp($host);
        if ($pinnedIp === null) {
            return new \WP_Error(
                'ssrf_blocked',
                "Blocked: {$host} resolves to no public IP addresses"
            );
        }

        self::$dnsCache[$host] = [
            'ip'      => $pinnedIp,
            'expires' => time() + self::DNS_CACHE_TTL,
        ];

        return ['host' => $host, 'port' => $port, 'ip' => $pinnedIp];
    }

    /**
     * Resolve a host to its first valid public IP (A or AAAA).
     *
     * Collects both A and AAAA records and returns the first entry that
     * passes FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE. This
     * closes mixed-record attacks where an A record passes validation but
     * cURL picks a private AAAA at connect time.
     *
     * Note: PHP's userland resolver has no per-call timeout knob; the OS
     * resolver timeout applies (typically 5s). Callers combine this with
     * the per-request cache above and SafeHttpClient's tight default
     * transport timeout to bound worker-blocking exposure.
     */
    private static function resolvePublicIp(string $host): ?string
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        $ipv4 = gethostbyname($host);
        if ($ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP, $flags)) {
            return $ipv4;
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                $ipv6 = $record['ipv6'] ?? '';
                if ($ipv6 !== '' && filter_var($ipv6, FILTER_VALIDATE_IP, $flags)) {
                    return $ipv6;
                }
            }
        }

        return null;
    }

    /**
     * Pin a hostname to a resolved IP so cURL cannot re-resolve DNS between
     * our validation and the actual TCP connect.
     *
     * Sets CURLOPT_RESOLVE via WordPress's `http_api_curl` action hook. The
     * Host header keeps the original hostname (TLS SNI + name-based vhosts
     * still work) but the underlying TCP connection goes to the pinned IP.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function injectCurlResolve(array $args, string $host, int $port, string $ip): array
    {
        self::$pinnedResolves[$host] = "{$host}:{$port}:{$ip}";

        if (!self::$hookRegistered) {
            add_action('http_api_curl', [self::class, 'applyCurlResolve'], 99, 3);
            self::$hookRegistered = true;
        }

        return $args;
    }

    /**
     * `http_api_curl` callback: apply pinned DNS entries to the cURL handle.
     *
     * Public because it must be hookable. Not part of the documented API.
     *
     * @param resource|\CurlHandle $handle
     * @param array<string, mixed> $parsedArgs
     */
    public static function applyCurlResolve(&$handle, array $parsedArgs, string $url): void
    {
        if (empty(self::$pinnedResolves)) {
            return;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host !== '' && isset(self::$pinnedResolves[$host]) && $handle instanceof \CurlHandle) {
            curl_setopt($handle, CURLOPT_RESOLVE, [self::$pinnedResolves[$host]]);
        }
        // Pin clearing is owned by get()/post()'s finally block so that an
        // exception, early return, or redirect on a different host cannot
        // leave a stale pin resident across requests in long-lived PHP-FPM
        // workers.
    }
}
