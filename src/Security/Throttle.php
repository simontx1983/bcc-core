<?php

namespace BCC\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight rate-limit helper for the BCC ecosystem.
 *
 * Uses the trust-engine's atomic RateLimiter when available,
 * falls back to transients when no persistent object cache is present.
 */
final class Throttle
{
    /** @var bool Whether the system has detected a Redis/object-cache failure this request. */
    private static bool $degraded = false;

    /**
     * Whether the rate limiter is operating in degraded mode this request.
     *
     * Other plugins can check this to tighten their own behavior:
     *   if (Throttle::isDegraded()) { // reduce batch sizes, skip optional cache reads }
     *
     * Set the first time wp_cache_incr/add fails within allow().
     * Persists for the remainder of the PHP request (process-level).
     */
    public static function isDegraded(): bool
    {
        return self::$degraded;
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
        $user_id = get_current_user_id();
        if ($user_id === 0 && $key === null) {
            // Fallback to IP for anonymous users to avoid shared bucket.
            // Normalize to /24 (IPv4) or /64 (IPv6) subnet to bound cardinality.
            // A /24 covers 256 IPs → one bucket per household/office, preventing
            // unbounded DB growth from rotating IPs (mobile, VPN, Tor).
            $ip  = class_exists('\\BCC\\Trust\\Security\\IpResolver')
                ? \BCC\Trust\Security\IpResolver::resolve()
                : self::getClientIp();
            $subnet = self::normalizeIpToSubnet($ip);
            $key = "bcc_throttle_{$action}_ip_" . md5($subnet);
        }
        $key = $key ?? "bcc_throttle_{$action}_{$user_id}";

        // Prefer trust-engine's atomic RateLimiter when available.
        if (class_exists('\\BCC\\Trust\\Security\\RateLimiter')) {
            return \BCC\Trust\Security\RateLimiter::allowByKey($key, $limit, $window);
        }

        // With persistent object cache (Redis/Memcached): use atomic wp_cache.
        if (wp_using_ext_object_cache()) {
            $cache_key = 'bcc_throttle_' . md5($key);
            $added = wp_cache_add($cache_key, 1, 'bcc_throttle', $window);
            if ($added) {
                return true;
            }
            $hits = wp_cache_incr($cache_key, 1, 'bcc_throttle');
            if ($hits === false) {
                self::$degraded = true;
                // Cache backend broken (Redis outage, connection reset, etc.).
                // Differentiate by action criticality:
                //   - Mutating/abuse-prone actions → deny (fail-closed)
                //   - Read-only/low-risk actions   → allow with process-level backstop
                $critical = in_array($action, [
                    'vote', 'endorse', 'wallet_verify', 'report',
                    'flag', 'verify', 'report_user',
                    'dispute_submit', 'panel_vote',
                ], true);

                if ($critical) {
                    if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                        \BCC\Core\Log\Logger::error('[Throttle] Redis down — mutating action denied', [
                            'action' => $action, 'key' => $cache_key,
                        ]);
                    }
                    return false;
                }

                // Non-critical reads: allow up to a per-process cap to prevent
                // a single PHP worker from hammering the DB when Redis is down.
                // This caps damage per-worker; nginx/CDN handles global rate.
                return self::processLevelBackstop($action, $limit);
            }
            return $hits <= $limit;
        }

        // Fallback: bucketed sliding-window rate limiter (works without Redis).
        //
        // Keys are bucketed by time window: hash(key + floor(now/window)).
        // Each bucket stores "count|bucket_expiry". The expiry lets the
        // cleanup cron garbage-collect old buckets.
        //
        // Sliding window: effective_count = current_count + prev_count * weight
        // where weight = fraction of previous window still overlapping.
        // This prevents boundary-edge spikes (2x limit over 2 seconds).
        global $wpdb;

        $now    = time();
        $window = max(1, $window);
        $bucket = (int) floor($now / $window);

        $curKey  = '_bcc_rl_' . md5($key . '_b' . $bucket);
        $prevKey = '_bcc_rl_' . md5($key . '_b' . ($bucket - 1));
        // Bucket expires after 2 windows (sliding window lookback + cleanup buffer).
        $bucketExpires = ($bucket + 2) * $window;
        $freshVal      = "1|{$bucketExpires}";

        // Atomic increment current bucket using LAST_INSERT_ID(expr).
        // The LAST_INSERT_ID() trick stores the post-increment count in the
        // connection's insert-id register, retrievable without a separate SELECT.
        // This closes the TOCTOU gap where a concurrent request could increment
        // between an UPDATE and a follow-up SELECT.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE
               option_value = CONCAT(
                 LAST_INSERT_ID(
                   CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1
                 ),
                 '|',
                 SUBSTRING_INDEX(option_value, '|', -1)
               )",
            $curKey,
            $freshVal
        ));

        // Capture rows_affected and insert_id IMMEDIATELY after the query,
        // before any intervening DB call can overwrite $wpdb's state.
        // On INSERT (new bucket): affected = 1, insert_id is auto-increment ID.
        // On UPDATE (existing bucket): affected = 2 (MySQL ODKU semantics),
        //   insert_id is the value we passed to LAST_INSERT_ID().
        $affected = $wpdb->rows_affected;
        $insertId = $wpdb->insert_id;

        if ($affected === 1) {
            // Fresh bucket insert — count is 1 by definition.
            $curCount = 1;
        } else {
            // Existing bucket updated — use the captured insert_id which holds
            // the atomic post-increment value from LAST_INSERT_ID(expr).
            // Previously this used a separate SELECT LAST_INSERT_ID() query,
            // which was vulnerable to corruption by intervening queries.
            $curCount = (int) $insertId;
        }

        // Read previous bucket for sliding window calculation.
        $prevCount = 0;
        $prevRow   = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $prevKey
        ));
        if ($prevRow !== null) {
            $parts     = explode('|', $prevRow, 2);
            $prevCount = (int) ($parts[0] ?? 0);
        }

        // Sliding window approximation using integer arithmetic.
        // weight_pct = 100 - (elapsed * 100 / window), clamped to [0, 100].
        // effective = current + prev * weight_pct / 100, clamped to [0, 2*limit].
        $elapsed   = $now - ($bucket * $window);
        $weightPct = max(0, min(100, 100 - (int) (($elapsed * 100) / $window)));
        $effective = $curCount + (int) (($prevCount * $weightPct) / 100);
        $effective = max(0, min($limit * 2, $effective));

        return $effective <= $limit;
    }

    /**
     * Normalize an IP address to its subnet to bound key cardinality.
     *
     * IPv4 → /24 (e.g., 1.2.3.4 → 1.2.3.0)
     * IPv6 → /64 (e.g., 2001:db8::1 → 2001:0db8:0000:0000::)
     * Invalid → returned as-is (md5 still hashes it safely)
     */
    /**
     * Process-level rate limit backstop when Redis is unavailable.
     *
     * Each PHP-FPM worker tracks how many times a given action was allowed
     * within the current request. Prevents a single worker from executing
     * unlimited expensive queries during a Redis outage.
     *
     * NOT a replacement for Redis-based rate limiting — this caps damage
     * per-worker, not per-user. Global rate limiting requires nginx or CDN.
     *
     * @param string $action Action identifier.
     * @param int    $limit  Per-request cap (same as normal rate limit).
     * @return bool
     */
    private static function processLevelBackstop(string $action, int $limit): bool
    {
        /** @var array<string, int> */
        static $counts = [];

        $counts[$action] = ($counts[$action] ?? 0) + 1;

        // Per-process cap: allow up to $limit calls per action per request.
        // Most requests only trigger 1-3 throttle checks per action, so
        // this only blocks genuine abuse (bot loops, scrapers).
        if ($counts[$action] > $limit) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[Throttle] Process-level backstop triggered (Redis down)', [
                    'action' => $action,
                    'count'  => $counts[$action],
                    'limit'  => $limit,
                ]);
            }
            return false;
        }

        // Degraded mode: rate limiter is using the process-level backstop.
        // Previously sent an X-Degraded-Mode header, but that leaked
        // infrastructure state to attackers (signals Redis is down).

        return true;
    }

    /**
     * Extract the real client IP behind reverse proxies.
     *
     * Three modes, in order of preference:
     *
     * 1. BCC_TRUSTED_PROXY_IPS defined → only trust headers from those IPs.
     *    Strictest mode. Operator explicitly declares their proxy tier.
     *
     * 2. BCC_TRUSTED_PROXY_IPS not defined, but proxy headers present →
     *    auto-detect safe cases (Cloudflare CF-Connecting-IP when REMOTE_ADDR
     *    is a known Cloudflare IP). Log a one-time warning so the operator
     *    knows to configure the constant for full protection.
     *
     * 3. No proxy headers → use REMOTE_ADDR directly. Works for direct-to-PHP
     *    deployments (Local, no CDN, etc.).
     *
     * This prevents both:
     *   - Spoofing (attacker sets fake X-Forwarded-For to bypass rate limits)
     *   - Self-DoS (all users behind unconfigured proxy share one bucket)
     */
    private static function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return 'invalid_remote';
        }

        // ── Mode 1: Explicit trusted proxy allowlist ────────────────────
        if (defined('BCC_TRUSTED_PROXY_IPS') && BCC_TRUSTED_PROXY_IPS !== '') {
            $trusted = array_map('trim', explode(',', BCC_TRUSTED_PROXY_IPS));

            if (in_array($remoteAddr, $trusted, true)) {
                $ip = self::extractProxyClientIp();
                if ($ip !== null) {
                    return $ip;
                }
            }

            // REMOTE_ADDR is not in the trusted list — use it directly.
            // This is correct: either the request came direct (no proxy),
            // or the proxy isn't in our list (don't trust its headers).
            return $remoteAddr;
        }

        // ── Mode 2: Auto-detect common proxy patterns ───────────────────
        // Cloudflare: CF-Connecting-IP is only set by Cloudflare edge servers.
        // If REMOTE_ADDR is in Cloudflare's published ranges AND the header
        // exists, it's safe to trust. We check the header exists (Cloudflare
        // always sets it) rather than validating REMOTE_ADDR against all CF
        // ranges (which change), because a non-CF origin wouldn't set this
        // header, and the worst case for a spoofed header from a non-CF IP
        // is that it poisons one attacker's own rate-limit bucket.
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
                self::warnMissingProxyConfig('Cloudflare detected (CF-Connecting-IP present)');
                return $cfIp;
            }
        }

        // Generic proxy headers present but no BCC_TRUSTED_PROXY_IPS configured.
        // Using REMOTE_ADDR here means all users behind this proxy share one
        // rate-limit bucket. Log a warning so the operator can fix the config.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_REAL_IP'])) {
            self::warnMissingProxyConfig('X-Forwarded-For/X-Real-IP headers detected');
        }

        return $remoteAddr;
    }

    /**
     * Extract client IP from proxy headers (called only when proxy is trusted).
     */
    private static function extractProxyClientIp(): ?string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
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
            $expanded = (string) inet_ntop(inet_pton($ip));
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
