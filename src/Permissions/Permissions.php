<?php

namespace BCC\Core\Permissions;

use BCC\Core\PeepSo\PeepSo;
use BCC\Core\ServiceLocator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralised permission checks for the BCC ecosystem.
 *
 * Every method is static and returns a boolean — no side-effects.
 */
final class Permissions
{
    private const CACHE_GROUP = 'bcc_trust';
    private const CACHE_TTL   = 60;

    /**
     * Whether the current user has the manage_options capability.
     */
    public static function is_admin(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Whether the given user is NOT suspended.
     *
     * When the trust engine is unavailable, the NullTrustReadService
     * returns isSuspended()=true (fail-closed). Admins with
     * manage_options capability bypass this gate so they can still
     * administer the site during trust-engine downtime.
     *
     * Result is cached for 60 seconds per user.
     */
    public static function is_not_suspended(?int $user_id = null): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return false;
        }

        // Admins always bypass suspension — critical for maintaining the
        // site when the trust engine is down (fail-closed NullObject).
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // SECURITY: Cache key includes an HMAC so co-installed plugins cannot
        // predict or construct valid keys to poison the suspension cache.
        $cacheKey = self::buildSuspensionCacheKey($user_id);
        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return !$cached;
        }

        // Delegates to the trust-engine implementation (or NullTrustReadService
        // which returns true — fail-closed) — no concrete coupling required.
        $isSuspended = ServiceLocator::resolveTrustReadService()->isSuspended($user_id);
        wp_cache_set($cacheKey, $isSuspended, self::CACHE_GROUP, self::CACHE_TTL);

        return !$isSuspended;
    }

    /**
     * Flush the suspension cache for a specific user.
     *
     * Call this (or fire the `bcc_user_suspension_changed` action with the
     * user ID) whenever a user's suspension status changes so that
     * is_not_suspended() picks up the new value immediately.
     */
    public static function flushSuspensionCache(int $userId): void
    {
        $key = self::buildSuspensionCacheKey($userId);
        wp_cache_delete($key, self::CACHE_GROUP);
    }

    /**
     * Register hook listeners for cross-plugin cache invalidation.
     *
     * Should be called once during plugin boot (e.g. from the main plugin file).
     */
    public static function registerHooks(): void
    {
        add_action('bcc_user_suspension_changed', [self::class, 'flushSuspensionCache']);
    }

    /**
     * Build the HMAC-secured cache key for a user's suspension status.
     */
    private static function buildSuspensionCacheKey(int $userId): string
    {
        $salt = defined('NONCE_SALT') ? NONCE_SALT : 'bcc-fallback-salt';
        return 'bcc_susp_' . hash_hmac('sha256', (string) $userId, $salt);
    }

    /**
     * Standard REST permission callback: logged-in + not suspended.
     *
     * Use as 'permission_callback' => [Permissions::class, 'restCallback']
     * instead of duplicating the check in each controller.
     */
    public static function restCallback(): bool
    {
        return is_user_logged_in() && self::is_not_suspended();
    }

    /**
     * Whether the given user owns a PeepSo page.
     */
    public static function owns_page(int $page_id, int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id || !$page_id) {
            return false;
        }

        // PeepSo::get_page_owner() delegates to the PageOwnerResolverInterface.
        // NullPageOwnerResolver already falls back to WP post_author, so no
        // additional fallback is needed here.
        $owner_id = PeepSo::get_page_owner($page_id);

        return $owner_id === $user_id;
    }
}
