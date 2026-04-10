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
                return true;
            }
            return $hits <= $limit;
        }

        // Fallback: atomic DB-based counter (works without Redis).
        // Uses INSERT … ON DUPLICATE KEY UPDATE to avoid the TOCTOU race
        // that the old transient approach suffered from under concurrency.
        // Value format: "count|expires_at" — same convention as RateLimiter.
        global $wpdb;

        $option_name = '_bcc_rl_' . md5($key);
        $now         = time();
        $expires     = $now + $window;

        // Atomic increment — succeeds only when window is still active
        // AND count is below the limit.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = CONCAT(
                 CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1,
                 '|',
                 SUBSTRING_INDEX(option_value, '|', -1)
             )
             WHERE option_name = %s
               AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) > %d
               AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d",
            $option_name,
            $now,
            $limit
        ));

        if ($updated > 0) {
            return true;
        }

        // Row missing or window expired — try to insert a fresh window.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')",
            $option_name,
            "1|{$expires}"
        ));

        if ($inserted > 0) {
            return true;
        }

        // Lost the INSERT race — row exists. Reset if expired.
        $reset = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = %s
             WHERE option_name = %s
               AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) <= %d",
            "1|{$expires}",
            $option_name,
            $now
        ));

        if ($reset > 0) {
            return true;
        }

        // Another thread already reset — try one more atomic increment.
        $retry = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = CONCAT(
                 CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1,
                 '|',
                 SUBSTRING_INDEX(option_value, '|', -1)
             )
             WHERE option_name = %s
               AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) > %d
               AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d",
            $option_name,
            $now,
            $limit
        ));

        return $retry > 0;
    }
}
