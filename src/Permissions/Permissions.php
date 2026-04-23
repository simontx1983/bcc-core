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
     * manage_options capability bypass this gate BY DEFAULT so they
     * can still administer the site during trust-engine downtime.
     *
     * $allowAdminBypass:
     *   - true  (default) — admins always pass. Use for admin screens
     *     and any path where a suspended admin must still be able to
     *     administer recovery.
     *   - false — admins are subject to the same suspension gate as
     *     any other user. Use for participant paths where an admin
     *     should NOT get a free pass (e.g. dispute REST endpoints: a
     *     suspended admin should not be able to file disputes or cast
     *     panel votes). Callers that choose this mode MUST treat
     *     admins as ordinary users for gating purposes.
     *
     * Result is cached for 60 seconds per user.
     */
    public static function is_not_suspended(?int $user_id = null, bool $allowAdminBypass = true): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $isAdmin = user_can($user_id, 'manage_options');

        // Admin bypass: only when allowed by the caller. Admin UIs keep
        // the bypass; dispute/participation paths opt out.
        if ($allowAdminBypass && $isAdmin) {
            return true;
        }

        // SECURITY: Cache key includes an HMAC so co-installed plugins cannot
        // predict or construct valid keys to poison the suspension cache.
        $cacheKey = self::buildSuspensionCacheKey($user_id);

        // Use the $found out-param to distinguish a genuine MISS from a
        // cached `false` value. Without it, `wp_cache_get` returning false
        // is ambiguous — and `false` is exactly the value we store for the
        // common case (user is NOT suspended). The previous `$cached !== false`
        // check meant non-suspended users NEVER hit the cache, forcing every
        // REST permission_callback to re-query the trust engine.
        $found  = false;
        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP, false, $found);
        if ($found) {
            $isSuspendedCached = (bool) $cached;
            if ($isSuspendedCached && $isAdmin && !$allowAdminBypass) {
                self::logSuspendedAdminBlocked($user_id);
            }
            return !$isSuspendedCached;
        }

        // Delegates to the trust-engine implementation (or NullTrustReadService
        // which returns true — fail-closed) — no concrete coupling required.
        $isSuspended = ServiceLocator::resolveTrustReadService()->isSuspended($user_id);
        wp_cache_set($cacheKey, $isSuspended, self::CACHE_GROUP, self::CACHE_TTL);

        if ($isSuspended && $isAdmin && !$allowAdminBypass) {
            self::logSuspendedAdminBlocked($user_id);
        }

        return !$isSuspended;
    }

    /**
     * Log an informational record when a suspended admin is blocked from a
     * participation path (dispute REST endpoints, etc.). Admin bypass for
     * admin screens continues — this log fires only for the $allowAdminBypass
     * = false case, so ops can see misuse attempts without spamming the
     * log on normal admin-screen traffic.
     */
    private static function logSuspendedAdminBlocked(int $userId): void
    {
        if (!class_exists('\\BCC\\Core\\Log\\Logger')) {
            return;
        }
        \BCC\Core\Log\Logger::info('[Permissions] Suspended admin blocked from dispute participation', [
            'user_id' => $userId,
        ]);
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
     *
     * SECURITY: this explicitly passes $allowAdminBypass = false so that a
     * suspended administrator is still denied REST-driven mutating actions
     * (vote, endorse, dispute submit, etc.). is_not_suspended()'s default
     * admin-bypass is intended for admin-screen rendering only — do NOT
     * reuse this callback for admin-ops endpoints that require suspension
     * to be bypassable; use restCallbackAdminAllowed() below instead.
     */
    public static function restCallback(): bool
    {
        return is_user_logged_in() && self::is_not_suspended(null, false);
    }

    /**
     * REST permission callback for endpoints that must remain available to
     * admins even if they are suspended (admin-ops actions, help links).
     *
     * Callers opt in explicitly — default {@see self::restCallback} is
     * fail-closed against suspended admins.
     */
    public static function restCallbackAdminAllowed(): bool
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
