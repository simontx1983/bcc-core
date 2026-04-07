<?php

namespace BCC\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight rate-limit helper for the BCC ecosystem.
 *
 * Uses the trust-engine's atomic RateLimiter when available,
 * falls back to WordPress transients.
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

        // Fallback: transient-based (non-atomic, acceptable for low traffic).
        $hits = (int) get_transient($key);
        if ($hits >= $limit) {
            return false;
        }
        set_transient($key, $hits + 1, $window);

        return true;
    }
}
