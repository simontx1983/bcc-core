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

    /**
     * Maximum cURL easy-handles in flight at once in a same-host batch.
     *
     * A batch of N URLs is drained in waves of at most this many concurrent
     * sockets, so a 100-URL gallery fetch does not open 100 simultaneous
     * connections against a single upstream (which upstreams rate-limit and
     * which would exhaust local fd/socket budget).
     */
    private const BATCH_MAX_CONCURRENCY = 12;

    /** DNS resolution cache TTL (seconds, request-local only). */
    private const DNS_CACHE_TTL = 60;

    /**
     * NEGATIVE DNS cache TTL — how long a FAILED resolve is remembered.
     *
     * ⚠⚠ WHY A SEPARATE, SHORTER TTL. The positive cache exists because,
     * as its own comment says, a burst of calls to one host would
     * otherwise "block on the OS resolver on every call" and slow
     * NXDOMAINs "become a worker-exhaustion vector". But it was written
     * ONLY ON SUCCESS — so the healthy path was cached and the
     * pathological one, the very case that comment names, re-paid the
     * full resolve every time. `ApiRetry` retries, so one synchronous
     * user request could pay it several times over.
     *
     * ⚠ And PHP cannot bound that cost: `gethostbyname()` takes NO
     * timeout argument and blocks on the OS resolver, whose own defaults
     * commonly reach ~30s and are not settable from PHP;
     * `dns_get_record()` is the same. A `timeout` in the request args
     * bounds the HTTP exchange only — it cannot cover the resolve,
     * because the resolve happens before the request is made.
     *
     * Shorter than the positive TTL on purpose: a cached FAILURE denies
     * a legitimate request, so it should expire quickly once DNS
     * recovers, whereas a cached success is merely a pin.
     */
    private const DNS_NEGATIVE_CACHE_TTL = 10;

    /**
     * Request-arg key carrying this request's OWN `CURLOPT_RESOLVE` entry,
     * as `"host:port:ip"`.
     *
     * ⭐⭐ THE PIN TRAVELS WITH THE REQUEST IT WAS VALIDATED FOR. It used to
     * live in a static array keyed by host, and that is what made two bugs
     * possible at once:
     *
     *   1. A LATER request to the same host inherited a pin it never
     *      earned. `prepareArgs()` is public and its docblock names
     *      `ApiRetry`'s retry loop as the intended caller, but the only
     *      release was a PRIVATE `clearPin()` called solely from
     *      {@see get()}/{@see post()}'s `finally`. A public acquire with a
     *      private release, so the one named caller could never release.
     *
     *   2. Bounding that static with a TTL instead — the first attempt at
     *      this fix — was WORSE, and the review that caught it was right.
     *      An expired pin was dropped and the request then went out with NO
     *      `CURLOPT_RESOLVE` at all, reopening the DNS-rebinding window the
     *      pin exists to close. And it was reachable: `ApiRetry` calls
     *      `prepareArgs()` ONCE outside its retry loop, deliberately, so
     *      with Solana's 30s timeout its attempts begin at 0, 32, 65 and 98
     *      seconds — the last two past any 60s TTL.
     *
     * ⇒ A TTL was the wrong MECHANISM, not a mistuned one: it approximates
     * "the dispatch this pin belongs to" with a clock, and no value is both
     * shorter than the staleness window and longer than a retry sequence.
     * Carrying the entry in the request's own args answers both bugs at the
     * root. A request that did not come from `prepareArgs()` carries no pin
     * and so cannot inherit one; a request that did carries its own and can
     * never be unpinned, however long its retries run. There is no shared
     * state and nothing to expire, so there is no branch left to fail open
     * through.
     *
     * ⚠ It cannot reach cURL as a request option. WordPress builds the
     * transport options from an explicit allow-list
     * (`class-wp-http.php:341`), so an unknown key is visible to hooks and
     * filters and nowhere else. Asserted, not assumed.
     *
     * ⚠ Underscore-prefixed to match WordPress's own convention for
     * internal request args (`_redirection`).
     */
    private const PIN_ARG = '_bcc_dns_pin';

    /**
     * Per-request DNS resolution cache keyed by host →
     * `['ip' => string|null, 'expires' => int]`.
     *
     * ⚠ `ip => null` is a remembered FAILURE (negative cache), not an
     * absent entry — see DNS_NEGATIVE_CACHE_TTL.
     * Reset on every request because PHP-FPM static lifetime would otherwise
     * outlive short-TTL records.
     *
     * @var array<string, array{ip: string|null, expires: int}>
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

        // No pin teardown to do: the entry is inside `$secured` and dies
        // with it. That is what removed the asymmetry in which these two
        // methods could release a pin and every other `prepareArgs()`
        // caller could not.
        return wp_remote_get($url, $secured);
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

        // See get(): nothing to release.
        return wp_remote_post($url, $secured);
    }

    /**
     * Validate that a URL is a safe public HTTP(S) target, WITHOUT sending it.
     *
     * Runs the exact same SSRF checks as `get()`/`post()` — scheme allowlist,
     * cloud-metadata host block, and private/reserved-IP rejection with public
     * DNS resolution — but performs no request and registers no cURL pin. Use
     * this when the actual transport is out of our hands (e.g. a third-party
     * library holds its own HTTP client) and we can only gate the URL before
     * handing it over. The single source of truth for what counts as "private"
     * or "blocked" stays in `validateAndPinUrl()`; callers MUST NOT re-implement
     * the IP-range logic.
     *
     * Note: this is a point-in-time check. When the eventual transport
     * re-resolves DNS at connect time (no pinning), a rebinding window remains —
     * so this is a strong ingress filter, not a TOCTOU-proof guarantee. For a
     * fully pinned request, use `get()`/`post()`/`getBatchSameHost()` instead.
     *
     * @return \WP_Error|null `null` when the URL is a safe public target;
     *                        `WP_Error` (codes `ssrf_invalid_url` /
     *                        `ssrf_invalid_scheme` / `ssrf_blocked`) when blocked.
     */
    public static function validatePublicUrl(string $url): ?\WP_Error
    {
        $result = self::validateAndPinUrl($url);
        return $result instanceof \WP_Error ? $result : null;
    }

    /**
     * Concurrent SSRF-safe GET of multiple URLs that all share ONE host.
     *
     * Specialized batch primitive for the "N URLs, one host" shape (e.g. a
     * gallery of media URLs served by a single CDN). The shared host is
     * validated, resolved, and DNS-pinned exactly ONCE via the same
     * `validateAndPinUrl()` path the single-request `get()` uses; every easy
     * handle then sets `CURLOPT_RESOLVE` to that SAME pin, closing the
     * TOCTOU/DNS-rebinding window per hop. Any URL whose host differs from the
     * validated one is rejected with a `WP_Error` for that index only — a
     * caller cannot smuggle a second, unvalidated host past the single
     * validation.
     *
     * All four single-request protections are preserved in the concurrent
     * path: (1) scheme/metadata/private-IP validation + public-IP resolution,
     * (2) the resolved IP pinned via CURLOPT_RESOLVE, (3) redirects disabled
     * (CURLOPT_FOLLOWLOCATION=false), (4) tight timeout + response-size cap
     * (aborted mid-transfer via the progress callback).
     *
     * Runs raw cURL (curl_multi), NOT wp_remote_*, because we own the pin and
     * the streaming size cap directly. Fails closed (WP_Error per index) if the
     * cURL extension is missing. Handles are drained in waves of at most
     * BATCH_MAX_CONCURRENCY and every handle is cleaned up in a `finally`, so
     * no socket or pin state leaks across requests on a long-lived worker.
     *
     * @param list<string>         $urls all must share the same host
     * @param array<string, mixed> $args timeout (capped 30s, default 3s),
     *                                    headers (array<string, string>),
     *                                    limit_response_size (bytes)
     * @return array<int, array{code: int, body: string}|\WP_Error> keyed by the
     *         SAME index as $urls. Each entry is an HTTP result (any status) or
     *         a WP_Error (SSRF block / transport error / timeout). Empty in → [].
     */
    public static function getBatchSameHost(array $urls, array $args = []): array
    {
        if ($urls === []) {
            return [];
        }

        if (!function_exists('curl_init') || !function_exists('curl_multi_init')) {
            return self::failEveryIndex(
                $urls,
                new \WP_Error(
                    'ssrf_no_curl',
                    'SafeHttpClient batch requires the PHP cURL extension.'
                )
            );
        }

        // Validate + resolve + pin the SHARED host exactly once, using the
        // first URL as the canonical host source. Every other URL is checked
        // against this host below.
        $urls   = array_values($urls);
        $first  = $urls[0];
        $pin    = self::validateAndPinUrl($first);
        if ($pin instanceof \WP_Error) {
            // The shared host itself is blocked/invalid — fail the whole batch.
            return self::failEveryIndex($urls, $pin);
        }

        $canonicalHost = strtolower((string) parse_url($first, PHP_URL_HOST));
        if ($canonicalHost === '') {
            return self::failEveryIndex(
                $urls,
                new \WP_Error('ssrf_invalid_url', 'Invalid URL: missing host')
            );
        }

        $timeout  = self::batchTimeout($args);
        $maxBytes = self::batchMaxBytes($args);
        $headers  = self::batchHeaders($args);
        // $pin is null for IP-literal hosts (no DNS to pin); non-null gives the
        // "host:port:ip" CURLOPT_RESOLVE entry to apply to every handle.
        $resolveEntry = $pin === null
            ? null
            : self::formatResolveEntry($pin['host'], (int) $pin['port'], $pin['ip']);

        // Index-aligned pass: any URL that does not match the single validated
        // host is rejected here, BEFORE a socket is opened, so it can never be
        // fetched against the wrong pin. This split is pure (no I/O) and so is
        // the unit-testable core of the same-host enforcement.
        [$fetchable, $results] = self::partitionByCanonicalHost($urls, $canonicalHost);

        // Drain fetchable URLs in bounded waves.
        foreach (array_chunk($fetchable, self::BATCH_MAX_CONCURRENCY, true) as $wave) {
            foreach (self::runWave($wave, $resolveEntry, $timeout, $maxBytes, $headers) as $i => $res) {
                $results[$i] = $res;
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * Split a batch of URLs into the fetchable set (host matches the single
     * validated host) and a pre-computed WP_Error result map for the rest.
     *
     * Pure — no DNS, no sockets. This is the same-host-enforcement gate: a URL
     * whose host differs from `$canonicalHost`, or which has no host at all, is
     * rejected as a WP_Error at its OWN index without touching the others, so a
     * caller cannot smuggle a second unvalidated host into the batch.
     *
     * @param list<string> $urls          index-aligned URL list (0-based, contiguous)
     * @param string       $canonicalHost lowercase host from the validated first URL
     * @return array{0: array<int, string>, 1: array<int, \WP_Error>}
     *         [ index→URL to fetch, index→WP_Error already-rejected ]
     */
    private static function partitionByCanonicalHost(array $urls, string $canonicalHost): array
    {
        /** @var array<int, string> $fetchable */
        $fetchable = [];
        /** @var array<int, \WP_Error> $rejected */
        $rejected = [];

        foreach ($urls as $i => $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') {
                $rejected[$i] = new \WP_Error('ssrf_invalid_url', 'Invalid URL: missing host');
                continue;
            }
            if ($host !== $canonicalHost) {
                $rejected[$i] = new \WP_Error(
                    'ssrf_host_mismatch',
                    "Batch URL host '{$host}' does not match the validated host '{$canonicalHost}'"
                );
                continue;
            }
            $fetchable[$i] = $url;
        }

        return [$fetchable, $rejected];
    }

    /**
     * Run one bounded wave of same-host GETs concurrently via curl_multi.
     *
     * @param array<int, string>       $wave        index → URL (all same host, pre-validated)
     * @param string|null              $resolveEntry "host:port:ip" pin, or null for IP-literal hosts
     * @param array<string, string>    $headers
     * @return array<int, array{code: int, body: string}|\WP_Error> index → result
     */
    private static function runWave(
        array $wave,
        ?string $resolveEntry,
        float $timeout,
        int $maxBytes,
        array $headers
    ): array {
        $multi = curl_multi_init();

        /** @var array<int, \CurlHandle> $handles index → easy handle */
        $handles = [];
        /** @var array<int, int> $bodyLen index → bytes written so far (for the size-cap abort) */
        $bodyLen = [];
        /** @var array<int, string> $bodies index → accumulated body */
        $bodies = [];
        $results = [];

        try {
            foreach ($wave as $i => $url) {
                // Guaranteed non-empty by partitionByCanonicalHost (every
                // fetchable URL has a host), but narrow the type for the
                // non-empty-string CURLOPT_URL contract.
                if ($url === '') {
                    $results[$i] = new \WP_Error('ssrf_invalid_url', 'Invalid URL: empty');
                    continue;
                }

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // No redirects: every hop could re-target a private IP and
                // slip past the pin.
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                // HTTP(S) only — refuse file://, gopher://, dict://, etc.,
                // both for the request and (defensively) any redirect target.
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                curl_setopt($ch, CURLOPT_TIMEOUT, (int) ceil($timeout));
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) ceil($timeout));
                curl_setopt($ch, CURLOPT_NOSIGNAL, true);

                if ($resolveEntry !== null) {
                    // The shared DNS pin: cURL connects to the pre-resolved
                    // public IP, not whatever DNS returns at connect time.
                    curl_setopt($ch, CURLOPT_RESOLVE, [$resolveEntry]);
                }

                if ($headers !== []) {
                    $headerLines = [];
                    foreach ($headers as $name => $value) {
                        $headerLines[] = "{$name}: {$value}";
                    }
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
                }

                $bodyLen[$i] = 0;
                $bodies[$i]  = '';
                // Streaming size cap: accumulate the body ourselves and abort
                // the transfer (return < length signals cURL to error out)
                // once we exceed the cap. This bounds per-handle memory even
                // for a hostile chunked/streamed response with no Content-Length.
                curl_setopt(
                    $ch,
                    CURLOPT_WRITEFUNCTION,
                    static function ($handle, string $chunk) use (&$bodyLen, &$bodies, $i, $maxBytes): int {
                        $bodyLen[$i] += strlen($chunk);
                        if ($bodyLen[$i] > $maxBytes) {
                            // Returning a short count aborts the transfer.
                            return -1;
                        }
                        $bodies[$i] .= $chunk;
                        return strlen($chunk);
                    }
                );

                $handles[$i] = $ch;
                curl_multi_add_handle($multi, $ch);
            }

            // Pump the multi handle until all transfers complete.
            $running = null;
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $i => $ch) {
                $errno = curl_errno($ch);
                if ($errno !== 0) {
                    // Distinguish the size-cap abort (WRITEFUNCTION short
                    // return surfaces as CURLE_WRITE_ERROR) from other
                    // transport errors for a clearer caller-facing code.
                    if ($errno === CURLE_WRITE_ERROR && $bodyLen[$i] > $maxBytes) {
                        $results[$i] = new \WP_Error(
                            'http_response_too_large',
                            "Response exceeded {$maxBytes} bytes and was aborted"
                        );
                    } else {
                        $results[$i] = new \WP_Error(
                            'http_request_failed',
                            curl_error($ch) !== '' ? curl_error($ch) : "cURL error {$errno}"
                        );
                    }
                    continue;
                }

                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $results[$i] = ['code' => $code, 'body' => $bodies[$i]];
            }
        } finally {
            // Clean up every handle + the multi handle so no socket or state
            // leaks across requests on a long-lived PHP-FPM worker.
            foreach ($handles as $ch) {
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            curl_multi_close($multi);
        }

        return $results;
    }

    /**
     * Resolve the batch transport timeout: default 3s, capped at 30s.
     *
     * @param array<string, mixed> $args
     */
    private static function batchTimeout(array $args): float
    {
        if (!isset($args['timeout']) || (float) $args['timeout'] <= 0) {
            return 3.0;
        }
        return min((float) $args['timeout'], 30.0);
    }

    /**
     * Resolve the batch per-response size cap: default 5 MiB.
     *
     * @param array<string, mixed> $args
     */
    private static function batchMaxBytes(array $args): int
    {
        if (!isset($args['limit_response_size']) || (int) $args['limit_response_size'] <= 0) {
            return self::DEFAULT_MAX_RESPONSE_BYTES;
        }
        return (int) $args['limit_response_size'];
    }

    /**
     * Normalise caller-supplied headers into a string→string map.
     *
     * @param array<string, mixed> $args
     * @return array<string, string>
     */
    private static function batchHeaders(array $args): array
    {
        $raw = $args['headers'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value) || is_float($value))) {
                $out[$name] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * Build an index-aligned result array where every entry is the same error.
     *
     * @param list<string> $urls
     * @return array<int, \WP_Error>
     */
    private static function failEveryIndex(array $urls, \WP_Error $error): array
    {
        $out = [];
        foreach (array_keys(array_values($urls)) as $i) {
            $out[$i] = $error;
        }
        return $out;
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

        $port = self::portOf($parsed);

        // Serve from per-request cache when fresh. A PHP-FPM worker
        // processing a burst of calls to the same host would otherwise
        // block on the OS resolver on every call, and hostile upstreams
        // (or merely slow NXDOMAINs) become a worker-exhaustion vector.
        //
        // ⚠ A cached entry with a NULL ip is a remembered FAILURE. It is
        // replayed as the same refusal rather than re-resolving, which is
        // the whole point: the resolve below cannot be time-bounded from
        // PHP, so not repeating it is the only lever available.
        if (isset(self::$dnsCache[$host]) && self::$dnsCache[$host]['expires'] > time()) {
            if (self::$dnsCache[$host]['ip'] === null) {
                return new \WP_Error(
                    'ssrf_blocked',
                    "Blocked: {$host} resolves to no public IP addresses"
                );
            }
            return ['host' => $host, 'port' => $port, 'ip' => self::$dnsCache[$host]['ip']];
        }

        $pinnedIp = self::resolvePublicIp($host);
        if ($pinnedIp === null) {
            // ⚠⚠ NEGATIVE-CACHE THE FAILURE. Without this, every retry in
            // the same request re-pays an unbounded `gethostbyname()`
            // — and on a host with no A record, an unbounded
            // `dns_get_record()` on top of it.
            //
            // ⚠ This CANNOT weaken the SSRF guard, which is the property
            // that makes it safe to cache a security decision: a cached
            // failure only ever produces a REFUSAL. The risk it does
            // carry is the opposite one — briefly refusing a host that
            // has since started resolving publicly — which is the
            // fail-closed direction and is why the TTL is short.
            self::$dnsCache[$host] = [
                'ip'      => null,
                'expires' => time() + self::DNS_NEGATIVE_CACHE_TTL,
            ];

            // Logged HERE rather than at the cache read, so it is emitted
            // once per TTL per host instead of once per blocked call: the
            // cache write IS the once-per-TTL gate. Guarded because
            // SafeHttpClient is loadable without the logger.
            // ⚠ BOTH conditions are the Logger's real preconditions, not
            // defensiveness. The class can be autoloaded without
            // WordPress, but `Logger::ensureInit()` resolves its log
            // path from WP_CONTENT_DIR — so without WP it raises
            // "Undefined constant", and SafeHttpClient is deliberately
            // loadable (and unit-testable) outside a booted WP. Guarding
            // on the constant keeps the log in production and silently
            // skips it where no log file can exist, rather than making
            // a pure-unit test write one.
            if (defined('WP_CONTENT_DIR') && class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning(
                    '[bcc-core] SafeHttpClient: host resolves to no public IP, negative-cached',
                    ['host' => $host, 'ttl' => self::DNS_NEGATIVE_CACHE_TTL]
                );
            }

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
        // The entry rides along in THIS request's args — see PIN_ARG for why
        // it is not a shared static any more.
        $args[self::PIN_ARG] = self::formatResolveEntry($host, $port, $ip);

        if (!self::$hookRegistered) {
            add_action('http_api_curl', [self::class, 'applyCurlResolve'], 99, 3);
            self::$hookRegistered = true;
        }

        return $args;
    }

    /**
     * The one place a `CURLOPT_RESOLVE` entry is formatted.
     *
     * Shared with {@see getBatchSameHost()}, which pins its own wave
     * directly rather than through the hook. The string was built in two
     * places before; one is enough.
     *
     * ⚠ `$ip` MAY BE IPv6, because {@see resolvePublicIp()} falls back to
     * an AAAA record — so the address can itself contain colons, and
     * anything parsing this back must split on the FIRST TWO colons only.
     * The host never contains one: an IP-literal host returns before
     * pinning, so a pinned host is always a DNS name.
     */
    private static function formatResolveEntry(string $host, int $port, string $ip): string
    {
        // ⚠ IPv6 IS BRACKETED. `resolvePublicIp()` falls back to an AAAA
        // record, so `$ip` can itself contain colons, and `host:port:[ip]` is
        // the form cURL documents for that case.
        //
        // ⚠ NOT A LIVE BUG TODAY, and worth being precise about rather than
        // overstating: the bare form was tested against the deployed libcurl
        // (8.16.0) and its own verbose output shows the entry accepted and
        // used — `Added a.invalid:9999:::1 to DNS cache`, then
        // `Trying [::1]:9999...`. So an AAAA-only host was NOT going out
        // unpinned.
        //
        // ⭐ What justifies the change is the general mechanism the same
        // probe exposed: an UNPARSEABLE entry is silently SKIPPED —
        // `Resolve address 'not-an-ip' found illegal` / `Couldn't parse
        // CURLOPT_RESOLVE entry` — and the request then proceeds with cURL's
        // own DNS, with no error returned to the caller. A pin that fails to
        // parse therefore fails OPEN. Emitting the documented form on every
        // libcurl build, not just the one measured here, is defence against
        // that class rather than a fix for an observed break.
        $isIpv6 = str_contains($ip, ':');

        return $isIpv6
            ? "{$host}:{$port}:[{$ip}]"
            : "{$host}:{$port}:{$ip}";
    }

    /**
     * The effective port of a parsed URL — explicit, or the scheme default.
     *
     * ⭐ ONE derivation, used by both the pin writer
     * ({@see validateAndPinUrl()}) and the pin reader
     * ({@see resolveEntryFor()}). They compare ports now, so two copies of
     * this rule could disagree and silently refuse every pin.
     *
     * @param array<string, mixed> $parsed output of `parse_url()`
     */
    private static function portOf(array $parsed): int
    {
        $scheme = is_string($parsed['scheme'] ?? null) ? $parsed['scheme'] : '';

        return (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * THE DECISION: which `CURLOPT_RESOLVE` entry, if any, belongs to this
     * request.
     *
     * ⭐ EXTRACTED SO IT CAN BE ASSERTED. There is no `curl_getopt`, so no
     * test can read `CURLOPT_RESOLVE` back off a handle; a test shaped like
     * "the handle was configured" would be theatre.
     * {@see applyCurlResolve()} is therefore a thin wrapper that applies
     * whatever this returns, and the logic lives here where it can be
     * checked. What is NOT covered either way is the `curl_setopt()` call
     * itself, and that is stated rather than implied.
     *
     * Returns null when this request carries no pin — which is the correct
     * answer, not a failure: such a request never went through
     * {@see prepareArgs()}, was never SSRF-validated, and is not ours to
     * pin. Inheriting someone else's entry is precisely the bug this
     * replaced.
     *
     * @param array<string, mixed> $parsedArgs
     */
    public static function resolveEntryFor(array $parsedArgs, string $url): ?string
    {
        $entry = $parsedArgs[self::PIN_ARG] ?? null;
        if (!is_string($entry) || $entry === '') {
            return null;
        }

        // host : port : ip — limit 3 because the ip may be IPv6.
        $parts = explode(':', $entry, 3);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[2] === '') {
            return null;
        }

        $parsedUrl = parse_url($url);
        if (!is_array($parsedUrl)) {
            return null;
        }

        $requestHost = is_string($parsedUrl['host'] ?? null) ? $parsedUrl['host'] : '';
        if ($requestHost === '') {
            return null;
        }

        // ⚠ CASE-INSENSITIVE, because DNS names are. The old static lookup
        // compared raw-case array keys and so was case-SENSITIVE; it only
        // ever worked because the same `$url` string was used to pin and to
        // look up. Args can be reused across URL spellings, so the compare
        // has to be the one DNS actually uses.
        //
        // ⚠ No IDN normalisation, and that is consistent rather than an
        // omission: nothing in this class converts Unicode hosts to ASCII,
        // including the BLOCKED_HOSTS check, so a Unicode host and its
        // punycode form are different hosts throughout. A Unicode host also
        // fails to resolve and is refused before reaching here.
        if (strcasecmp($parts[0], $requestHost) !== 0) {
            return null;
        }

        // ⚠ THE PORT HAS TO MATCH TOO, and getting this wrong fails OPEN.
        // A `CURLOPT_RESOLVE` entry only applies to the host AND port it
        // names, so handing cURL `host:443:ip` for a port-80 connection does
        // not misdirect anything — it simply does not match, and cURL
        // resolves the name itself. The request would go out UNPINNED while
        // this method reported a pin, which is the shape of defect review
        // caught in the TTL approach.
        //
        // Not reachable today, because each `prepareArgs()` call pairs with
        // one URL. It is latent for exactly the reason the host check is:
        // args are an ordinary array and nothing stops a caller reusing them.
        if ((int) $parts[1] !== self::portOf($parsedUrl)) {
            return null;
        }

        return $entry;
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
        $entry = self::resolveEntryFor($parsedArgs, $url);
        if ($entry === null) {
            return;
        }

        // ⚠ The `instanceof` guard is for a handle this class does not
        // control: another `http_api_curl` listener may replace it, and
        // before PHP 8 a cURL handle was a `resource`. There is no
        // fail-open branch behind it any more — a request that carries a
        // pin always has one to apply.
        if ($handle instanceof \CurlHandle) {
            curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
        }
    }
}
