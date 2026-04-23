<?php

namespace BCC\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight rate-limit helper for the BCC ecosystem.
 *
 * Production requirement: EITHER the trust-engine's atomic RateLimiter OR a
 * persistent object cache (Redis / Memcached) must be available. When neither
 * is present, allow() FAILS CLOSED for every action at the isReady() gate.
 * A previous release carried a wp_options-backed sliding-window fallback;
 * that fallback caused write amplification on the most-contended WP table
 * under load and has been removed. See self::isReady() and the admin notice
 * in bcc-core/bcc-core.php.
 */
final class Throttle
{
    /**
     * Action identifiers that must fail-closed (deny) on cache-backend
     * failure. Any action not in this list gets the process-level
     * backstop (which also denies today, but the behaviour is explicit
     * here so a future restructuring that re-introduces soft-degrade
     * for non-critical actions cannot silently relax abuse protection
     * on a mutating endpoint.)
     */
    private const CRITICAL_ACTIONS = [
        // bcc-trust / bcc-disputes mutating actions
        'vote', 'endorse', 'wallet_verify', 'report',
        'flag', 'verify', 'report_user',
        'dispute_submit', 'panel_vote',
        // bcc-peepso mutating AJAX actions. Previously relied on the
        // always-deny processLevelBackstop to fail closed; explicit
        // membership here prevents that implicit reliance from breaking
        // if the backstop policy ever widens again.
        'bcc_peepso.inline_edit',
        'bcc_peepso.visibility',
        'bcc_peepso.gallery_upload',
        'bcc_peepso.gallery_delete',
        'bcc_peepso.gallery_reorder',
        'bcc_peepso.gallery_bulk_delete',
        'bcc_peepso.repeater_delete',
        'bcc_peepso.repeater_reorder',
    ];

    /** @var bool Whether the system has detected a Redis/object-cache failure this request. */
    private static bool $degraded = false;

    /** @var bool Have we already consulted the shared degraded flag this request? */
    private static bool $sharedDegradedChecked = false;

    /**
     * Per-request set of (error-class, action) keys already logged. Prevents
     * a single PHP worker from writing the same "backend down" error line
     * hundreds of times during a cache outage — when every rate-limited
     * endpoint fails identically within one request lifecycle, one log line
     * per error-class-per-action carries all the diagnostic signal without
     * flooding disk and log-aggregation pipelines.
     *
     * @var array<string, true>
     */
    private static array $loggedOnce = [];

    /**
     * Shared degraded marker: site option storing the UNIX timestamp of the
     * most recent cache-layer failure observed by ANY PHP worker. When the
     * persistent object cache (Redis/Memcached) flaps, every worker
     * previously re-discovered the failure independently via wp_cache_incr
     * returning false — each discovery cost a cache round-trip and the
     * per-process $degraded flag never propagated. The option is authoritative
     * for ~30 seconds; stale entries self-expire on the first check after
     * DEGRADED_TTL passes.
     */
    private const DEGRADED_OPTION_KEY = 'bcc_throttle_degraded_until';
    private const DEGRADED_TTL        = 30;
    /** Minimum seconds between option writes to avoid hammering wp_options. */
    private const DEGRADED_WRITE_THROTTLE = 5;

    /**
     * Whether the rate limiter is operating in degraded mode.
     *
     * Other plugins can check this to tighten their own behavior:
     *   if (Throttle::isDegraded()) { // reduce batch sizes, skip optional cache reads }
     *
     * Returns true when:
     *   - this process has already observed a cache failure (sticky per-request), OR
     *   - another worker wrote the shared DEGRADED_OPTION_KEY within DEGRADED_TTL.
     */
    public static function isDegraded(): bool
    {
        if (self::$degraded) {
            return true;
        }
        self::loadSharedDegraded();
        return self::$degraded;
    }

    /**
     * Read the shared degraded flag once per request. A site option is used
     * (not a cache entry) because the whole point is to signal cache failure.
     */
    private static function loadSharedDegraded(): void
    {
        if (self::$sharedDegradedChecked) {
            return;
        }
        self::$sharedDegradedChecked = true;

        $until = (int) get_option(self::DEGRADED_OPTION_KEY, 0);
        if ($until > 0 && $until > time()) {
            self::$degraded = true;
        }
    }

    /**
     * Propagate the in-process degraded flag to a shared store so other
     * workers short-circuit without rediscovering the same cache failure.
     * Throttled to at most one write per DEGRADED_WRITE_THROTTLE seconds.
     */
    private static function markSharedDegraded(): void
    {
        $now       = time();
        $existing  = (int) get_option(self::DEGRADED_OPTION_KEY, 0);
        // Only write if the existing marker is absent, expired, or about to
        // expire. This keeps wp_options writes to once per bout of degradation.
        if ($existing > $now + (self::DEGRADED_TTL - self::DEGRADED_WRITE_THROTTLE)) {
            return;
        }
        update_option(self::DEGRADED_OPTION_KEY, $now + self::DEGRADED_TTL, false);
    }

    /**
     * Whether a safe rate-limiter backend is available.
     *
     * Returns true when EITHER:
     *  - the trust-engine's atomic RateLimiter class is loaded, OR
     *  - WordPress is using a persistent object cache (Redis / Memcached).
     *
     * When false, allow() returns false for every action (fail-closed).
     * Consumed by the bcc-core admin notice and the disputes /health
     * endpoint so operators can detect a missing backend before abuse
     * protection silently disengages.
     */
    public static function isReady(): bool
    {
        return class_exists('\\BCC\\Trust\\Security\\RateLimiter')
            || wp_using_ext_object_cache();
    }

    /**
     * Operational snapshot for health endpoints. Stable JSON shape:
     *   [
     *     'rate_limiter_ready' => bool,
     *     'backend'            => 'trust_engine' | 'object_cache' | 'none',
     *     'degraded'           => bool,
     *   ]
     *
     * @return array{rate_limiter_ready: bool, backend: string, degraded: bool}
     */
    public static function health(): array
    {
        if (class_exists('\\BCC\\Trust\\Security\\RateLimiter')) {
            $backend = 'trust_engine';
        } elseif (wp_using_ext_object_cache()) {
            $backend = 'object_cache';
        } else {
            $backend = 'none';
        }

        return [
            'rate_limiter_ready' => self::isReady(),
            'backend'            => $backend,
            'degraded'           => self::$degraded,
            'last_success_ts'    => self::lastSuccessTs(),
        ];
    }

    /** wp_cache group + key for the liveness probe. */
    private const LIVENESS_CACHE_GROUP = 'bcc_throttle_liveness';
    private const LIVENESS_CACHE_KEY   = 'last_success_ts';

    /** Site-option fallback when no persistent cache exists. Write-throttled. */
    private const LIVENESS_OPTION_KEY  = 'bcc_throttle_last_success_ts';
    private const LIVENESS_OPTION_TTL  = 60;

    /**
     * Record a successful rate-limit increment.
     *
     * Writes the current UNIX timestamp so /disputes/health can distinguish
     * "backend is up and passing requests" from "backend looks up but nothing
     * has incremented in N minutes". Writes are cheap with a persistent cache;
     * without one, falls back to a rate-limited site option so the metric is
     * still meaningful without burning a wp_options write per request.
     */
    private static function touchLastSuccess(): void
    {
        $now = time();

        if (wp_using_ext_object_cache()) {
            // Persistent cache: overwrite-on-every-success is cheap.
            wp_cache_set(self::LIVENESS_CACHE_KEY, $now, self::LIVENESS_CACHE_GROUP, 0);
            return;
        }

        // No persistent cache → rate-limit the option write to once per
        // LIVENESS_OPTION_TTL seconds so a burst of successes does not
        // pound wp_options.
        $last = (int) get_option(self::LIVENESS_OPTION_KEY, 0);
        if ($now - $last >= self::LIVENESS_OPTION_TTL) {
            update_option(self::LIVENESS_OPTION_KEY, $now, false);
        }
    }

    /**
     * UNIX timestamp of the most recent successful rate-limit increment,
     * or 0 if unknown. Cache first, option second.
     */
    public static function lastSuccessTs(): int
    {
        $cached = wp_cache_get(self::LIVENESS_CACHE_KEY, self::LIVENESS_CACHE_GROUP);
        if (is_int($cached) && $cached > 0) {
            return $cached;
        }
        return (int) get_option(self::LIVENESS_OPTION_KEY, 0);
    }

    /**
     * Check if an action should be allowed under rate limits.
     *
     * @param string      $action  Action identifier (e.g. 'gallery_upload').
     * @param int         $limit   Max requests per window.
     * @param int         $window  Window in seconds.
     * @param string|null $key     Custom key (defaults to action_userId).
     * @return bool True if allowed, false if throttled.
     */
    public static function allow(string $action, int $limit = 10, int $window = 60, ?string $key = null): bool
    {
        // FAIL CLOSED when no safe backend is available. Previously this
        // function silently fell through to a wp_options-backed sliding
        // window which, under load, created severe write amplification on
        // the most-contended WP table and turned abuse protection into a
        // site-wide slowdown vector. Operators MUST provision Redis (or
        // the trust-engine RateLimiter) — the bcc-core admin notice flags
        // the missing backend so this deny-all state is loud, not silent.
        // Short-circuit if another worker has already recorded a cache failure.
        // Cheap — static per-request after the first call.
        self::loadSharedDegraded();

        if (!self::isReady()) {
            self::$degraded = true;
            self::markSharedDegraded();
            self::logOnce('no_backend_' . $action, static function () use ($action): void {
                \BCC\Core\Log\Logger::error('[Throttle] No rate-limiter backend available — denying', [
                    'action' => $action,
                ]);
            });
            return false;
        }

        $user_id = get_current_user_id();
        if ($user_id === 0 && $key === null) {
            // Fallback to IP for anonymous users to avoid shared bucket.
            // Normalize to /24 (IPv4) or /64 (IPv6) subnet to bound cardinality.
            // A /24 covers 256 IPs → one bucket per household/office, preventing
            // unbounded DB growth from rotating IPs (mobile, VPN, Tor).
            $ip  = class_exists('\\BCC\\Trust\\Core\\Security\\IpResolver')
                ? \BCC\Trust\Core\Security\IpResolver::resolve()
                : self::getClientIp();
            $subnet = self::normalizeIpToSubnet($ip);
            $key = "bcc_throttle_{$action}_ip_" . md5($subnet);
        }
        $key = $key ?? "bcc_throttle_{$action}_{$user_id}";

        // Prefer bcc-trust's atomic RateLimiter when available.
        if (class_exists('\\BCC\\Trust\\Core\\Security\\RateLimiter')) {
            $ok = \BCC\Trust\Core\Security\RateLimiter::allowByKey($key, $limit, $window);
            if ($ok) {
                self::touchLastSuccess();
            }
            return $ok;
        }

        // With persistent object cache (Redis/Memcached): use atomic wp_cache.
        if (wp_using_ext_object_cache()) {
            $cache_key = 'bcc_throttle_' . md5($key);
            $added = wp_cache_add($cache_key, 1, 'bcc_throttle', $window);
            if ($added) {
                // Liveness round-trip: wp_cache_add can return true against
                // a local in-memory fallback when the persistent backend is
                // disconnected, producing per-worker buckets instead of the
                // intended shared bucket. Read back: if the value is not
                // exactly 1, the store is degraded and we must fail-closed
                // for mutating actions so attackers cannot multiply their
                // effective rate limit by the PHP-FPM pool size.
                $verify = wp_cache_get($cache_key, 'bcc_throttle');
                if ((int) $verify !== 1) {
                    self::$degraded = true;
                    self::markSharedDegraded();
                    $critical = in_array($action, self::CRITICAL_ACTIONS, true);
                    if ($critical) {
                        self::logOnce('liveness_fail_' . $action, static function () use ($action, $cache_key, $verify): void {
                            \BCC\Core\Log\Logger::error('[Throttle] wp_cache_add liveness failed — mutating action denied', [
                                'action' => $action, 'key' => $cache_key,
                                'verify' => var_export($verify, true),
                            ]);
                        });
                        return false;
                    }
                    return self::processLevelBackstop($action, $limit);
                }
                self::touchLastSuccess();
                return true;
            }
            $hits = wp_cache_incr($cache_key, 1, 'bcc_throttle');
            // Strict-typing guard: any non-positive-int return from
            // wp_cache_incr means the backend is unusable for atomic
            // counting on this request. Drop-in object caches vary on
            // the error return (false, 0, null, string); treat anything
            // that's not a clean positive int as degraded.
            if (!is_int($hits) || $hits <= 0) {
                self::$degraded = true;
                self::markSharedDegraded();
                // Cache backend broken (Redis outage, connection reset, etc.).
                // Differentiate by action criticality:
                //   - Mutating/abuse-prone actions → deny (fail-closed)
                //   - Read-only/low-risk actions   → allow with process-level backstop
                $critical = in_array($action, self::CRITICAL_ACTIONS, true);

                if ($critical) {
                    self::logOnce('redis_down_' . $action, static function () use ($action, $cache_key): void {
                        \BCC\Core\Log\Logger::error('[Throttle] Redis down — mutating action denied', [
                            'action' => $action, 'key' => $cache_key,
                        ]);
                    });
                    return false;
                }

                // Non-critical reads: allow up to a per-process cap to prevent
                // a single PHP worker from hammering the DB when Redis is down.
                // This caps damage per-worker; nginx/CDN handles global rate.
                return self::processLevelBackstop($action, $limit);
            }
            $allowed = $hits <= $limit;
            if ($allowed) {
                self::touchLastSuccess();
            }
            return $allowed;
        }

        // Unreachable by construction: isReady() returns true iff the
        // trust-engine RateLimiter class is loaded OR WordPress is using a
        // persistent object cache, and both paths above return before this
        // point. A previous release shipped a wp_options-backed sliding-
        // window fallback here — that fallback caused write amplification on
        // the most-contended WP table under load, and bcc-core now fails
        // closed at the isReady() gate instead. If this throw ever fires,
        // someone has broken the invariant in isReady() — do not silently
        // recover.
        throw new \LogicException(
            '[Throttle] allow() reached unreachable branch — isReady() invariant violated'
        );
    }

    /**
     * Normalize an IP address to its subnet to bound key cardinality.
     *
     * IPv4 → /24 (e.g., 1.2.3.4 → 1.2.3.0)
     * IPv6 → /64 (e.g., 2001:db8::1 → 2001:0db8:0000:0000::)
     * Invalid → returned as-is (md5 still hashes it safely)
     */
    /**
     * Process-level backstop — now ALWAYS fail-closed.
     *
     * The prior implementation allowed up to $limit calls per action per
     * PHP worker when Redis was unavailable. That "soft degrade" covered
     * the read-only, non-critical actions — but in practice it meant a
     * Redis outage silently relaxed abuse protection across the whole
     * fleet of PHP workers (each worker got its own budget), creating
     * an attacker-friendly window whenever infrastructure flapped.
     *
     * New policy: if Redis is unavailable AND we reached this path, the
     * action is denied outright. The isReady() gate at the top of allow()
     * also denies calls when no backend is available, so this path is
     * mostly dead code retained for completeness / future restructuring.
     *
     * @param string $action Action identifier.
     * @param int    $limit  (unused — retained for ABI stability)
     * @return bool Always false.
     */
    private static function processLevelBackstop(string $action, int $limit): bool
    {
        unset($limit); // parameter kept for signature stability

        self::$degraded = true;
        self::markSharedDegraded();

        self::logOnce('backstop_' . $action, static function () use ($action): void {
            \BCC\Core\Log\Logger::warning('[Throttle] Process-level backstop denied (Redis down)', [
                'action' => $action,
            ]);
        });

        return false;
    }

    /**
     * Invoke $logger only once per request for a given $key. Used to dedup
     * the high-volume "backend down" error lines so a Redis outage does not
     * generate hundreds of identical log entries per request lifecycle. The
     * callable indirection avoids building the log context (var_export, etc.)
     * on every suppressed call.
     */
    private static function logOnce(string $key, callable $logger): void
    {
        if (isset(self::$loggedOnce[$key])) {
            return;
        }
        self::$loggedOnce[$key] = true;
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            $logger();
        }
    }

    /**
     * Default Cloudflare IPv4 ranges (as published at
     * https://www.cloudflare.com/ips-v4). Used as the fallback when
     * the site transient is empty. An operator may override via the
     * `bcc_trusted_proxy_ips` filter if their topology is different.
     */
    private const CF_IPV4_DEFAULT = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /**
     * Default Cloudflare IPv6 ranges (https://www.cloudflare.com/ips-v6).
     */
    private const CF_IPV6_DEFAULT = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /** Site transient key + TTL for the cached CF ranges. */
    private const CF_RANGES_TRANSIENT = 'bcc_cf_ip_ranges';
    private const CF_RANGES_TTL       = 604800; // 7 days.

    /**
     * Extract the real client IP behind reverse proxies.
     *
     * Four modes, in order of preference:
     *
     * 1. BCC_TRUSTED_PROXY_IPS constant defined → strict allowlist.
     * 2. `bcc_trusted_proxy_ips` filter returns a non-empty list → strict
     *    allowlist from the filter (hot-swappable without redeploy).
     * 3. REMOTE_ADDR falls inside the cached Cloudflare IPv4/IPv6 ranges
     *    AND CF-Connecting-IP is present → trust CF-Connecting-IP.
     *    Validation is NOT optional here — see C1 fix.
     * 4. Otherwise → REMOTE_ADDR. If production (WP_DEBUG off) and no
     *    trusted-proxy config was resolved, log an error once so the
     *    operator is alerted without crashing the site.
     *
     * This closes the CF-Connecting-IP spoof vector that let any direct
     * attacker bypass rate limits by sending a forged header.
     */
    private static function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return 'invalid_remote';
        }

        // ── Mode 1: Explicit trusted proxy allowlist (constant) ─────────
        if (defined('BCC_TRUSTED_PROXY_IPS') && BCC_TRUSTED_PROXY_IPS !== '') {
            $trusted = array_map('trim', explode(',', BCC_TRUSTED_PROXY_IPS));

            if (in_array($remoteAddr, $trusted, true)) {
                $ip = self::extractProxyClientIp();
                if ($ip !== null) {
                    return $ip;
                }
            }

            // REMOTE_ADDR not in allowlist — treat request as direct.
            return $remoteAddr;
        }

        // ── Mode 2: Filter-based override ───────────────────────────────
        // Allows host configs to inject a trusted-proxy list from wp-config
        // or a mu-plugin without requiring the constant. Must be an array
        // of IP literals; non-array returns are ignored (fail-closed).
        /** @var mixed $filtered */
        $filtered = apply_filters('bcc_trusted_proxy_ips', null);
        if (is_array($filtered) && !empty($filtered)) {
            $trusted = array_values(array_filter(array_map(
                static fn($v) => is_string($v) ? trim($v) : '',
                $filtered
            )));

            if (!empty($trusted)) {
                if (in_array($remoteAddr, $trusted, true)) {
                    $ip = self::extractProxyClientIp();
                    if ($ip !== null) {
                        return $ip;
                    }
                }
                return $remoteAddr;
            }
        }

        // ── Mode 3: Cloudflare auto-detect (operator opt-in + validated) ─
        // CF-Connecting-IP is only trusted when:
        //   (a) the operator has explicitly opted in via the
        //       BCC_BEHIND_CLOUDFLARE constant (or the 'bcc_behind_cloudflare'
        //       filter for hot-swappable config), AND
        //   (b) REMOTE_ADDR is actually in a published Cloudflare range.
        //
        // Without the opt-in gate, any site whose traffic happens to
        // transit a CF-proxied egress (shared SaaS hosts, cloud egress
        // pools) would trust a header the SITE was never behind CF for —
        // letting any attacker on that egress spoof arbitrary client IPs.
        // With the opt-in gate, operators who are actually behind CF get
        // the validated fast path and everyone else falls through to
        // Mode 4 (direct REMOTE_ADDR).
        $cfOptIn = (defined('BCC_BEHIND_CLOUDFLARE') && BCC_BEHIND_CLOUDFLARE)
            || (bool) apply_filters('bcc_behind_cloudflare', false);

        if (
            $cfOptIn
            && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
            && self::remoteAddrIsCloudflare($remoteAddr)
        ) {
            $cfIp = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }

        // ── Mode 4: Direct / unconfigured ───────────────────────────────
        // Proxy headers present but REMOTE_ADDR is not Cloudflare AND no
        // trusted-proxy config is resolved → we must NOT trust those
        // headers. The log severity is keyed to wp_get_environment_type()
        // so only production pages ops; staging/development/local stay
        // at warning level to avoid alert fatigue from legitimate dev setups.
        if (
            !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
            || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
            || !empty($_SERVER['HTTP_X_REAL_IP'])
        ) {
            $envType = function_exists('wp_get_environment_type')
                ? wp_get_environment_type()
                : 'production';

            if ($envType === 'production') {
                self::logMissingProxyConfig(
                    'Proxy headers present but REMOTE_ADDR not in BCC_TRUSTED_PROXY_IPS, '
                    . 'bcc_trusted_proxy_ips filter, or Cloudflare ranges — headers ignored.'
                );
            } else {
                self::warnMissingProxyConfig(
                    'proxy headers present without trusted-proxy config (env=' . $envType . ')'
                );
            }
        }

        return $remoteAddr;
    }

    /**
     * True when $remoteAddr falls inside any cached Cloudflare range.
     *
     * Ranges are cached as a site transient for 7 days to avoid any
     * per-request network or option I/O beyond a single transient read.
     */
    private static function remoteAddrIsCloudflare(string $remoteAddr): bool
    {
        $ranges = self::getCloudflareRanges();
        $isV4   = filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $list   = $isV4 ? ($ranges['v4'] ?? []) : ($ranges['v6'] ?? []);

        foreach ($list as $cidr) {
            if (self::ipInCidr($remoteAddr, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cached Cloudflare ranges. Falls back to the hardcoded defaults
     * when the transient is missing or malformed.
     *
     * @return array{v4: list<string>, v6: list<string>}
     */
    private static function getCloudflareRanges(): array
    {
        $cached = function_exists('get_site_transient')
            ? get_site_transient(self::CF_RANGES_TRANSIENT)
            : false;

        if (
            is_array($cached)
            && isset($cached['v4'], $cached['v6'])
            && is_array($cached['v4'])
            && is_array($cached['v6'])
        ) {
            /** @var array{v4: list<string>, v6: list<string>} $cached */
            return $cached;
        }

        $ranges = [
            'v4' => self::CF_IPV4_DEFAULT,
            'v6' => self::CF_IPV6_DEFAULT,
        ];

        if (function_exists('set_site_transient')) {
            set_site_transient(self::CF_RANGES_TRANSIENT, $ranges, self::CF_RANGES_TTL);
        }

        return $ranges;
    }

    /**
     * Match an IP literal against a CIDR block (IPv4 or IPv6).
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        [$subnet, $bitsStr] = explode('/', $cidr, 2);
        $bits = (int) $bitsStr;

        $ipBin  = @inet_pton($ip);
        $netBin = @inet_pton($subnet);
        if ($ipBin === false || $netBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask);
    }

    /**
     * Extract client IP from proxy headers (called only when proxy is trusted).
     *
     * SECURITY: deliberately does NOT consult HTTP_CF_CONNECTING_IP. That
     * header is Cloudflare-specific and is reserved for the Mode-3 CF
     * auto-detect path, which validates REMOTE_ADDR is in a published
     * Cloudflare range before trusting it. If a non-Cloudflare proxy is
     * allowlisted here (Modes 1/2) and that proxy fails to strip
     * CF-Connecting-IP, an attacker can spoof it through the proxy and
     * poison any IP's bucket. By restricting this extractor to standard
     * forwarding headers (XFF, X-Real-IP), the explicit allowlist remains
     * safe even against proxies with sloppy header policies. Operators on
     * Cloudflare with an explicit BCC_TRUSTED_PROXY_IPS allowlist can rely
     * on X-Forwarded-For (which CF also sends).
     */
    private static function extractProxyClientIp(): ?string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    /**
     * Log a one-time warning per request when proxy headers are present
     * but BCC_TRUSTED_PROXY_IPS is not configured.
     */
    private static function warnMissingProxyConfig(string $reason): void
    {
        static $warned = false;
        if ($warned) {
            return;
        }
        $warned = true;

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[Throttle] BCC_TRUSTED_PROXY_IPS not configured — ' . $reason
                . '. Rate limiting may be less effective. Define BCC_TRUSTED_PROXY_IPS in wp-config.php.', [
                'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Log a one-time ERROR per request when production config is missing.
     *
     * Distinct from warnMissingProxyConfig() because on production
     * (WP_DEBUG off) spoofed proxy headers are an actively exploitable
     * misconfig, not a dev-time notice. We log-error instead of throwing
     * so the site stays up while ops is paged.
     */
    private static function logMissingProxyConfig(string $reason): void
    {
        static $errored = false;
        if ($errored) {
            return;
        }
        $errored = true;

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[Throttle] Trusted-proxy config missing in production — ' . $reason, [
                'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        }
    }

    private static function normalizeIpToSubnet(string $ip): string
    {
        // IPv4: mask to /24
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        // IPv6: mask to /64 (first 4 groups)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // inet_pton/inet_ntop can only fail for non-IPv6 input, but $ip is
            // validated above. Fail safe by returning the unnormalized IP —
            // rate limiting still works, just at a finer granularity than /64.
            $binary = inet_pton($ip);
            if ($binary === false) {
                return $ip;
            }
            $ntop = inet_ntop($binary);
            if ($ntop === false) {
                return $ip;
            }
            $expanded = $ntop;
            // inet_ntop may return compressed form; expand to full groups
            $full = implode(':', array_map(
                fn(string $g): string => str_pad($g, 4, '0', STR_PAD_LEFT),
                explode(':', str_replace('::', str_repeat(':0000', 8 - substr_count($expanded, ':')) . ':', $expanded))
            ));
            $groups = explode(':', $full);
            // Keep first 4 groups (/64), zero out the rest
            return implode(':', array_slice($groups, 0, 4)) . ':0:0:0:0';
        }

        return $ip;
    }
}
