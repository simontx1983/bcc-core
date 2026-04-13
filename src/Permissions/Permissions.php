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
        $salt = defined('NONCE_SALT') ? NONCE_SALT : 'bcc-fallback-salt';
        $cacheKey = 'bcc_susp_' . hash_hmac('sha256', (string) $user_id, $salt);
        $cached = wp_cache_get($cacheKey, 'bcc_trust');
        if ($cached !== false) {
            return !$cached;
        }

        // Delegates to the trust-engine implementation (or NullTrustReadService
        // which returns true — fail-closed) — no concrete coupling required.
        $isSuspended = ServiceLocator::resolveTrustReadService()->isSuspended($user_id);
        wp_cache_set($cacheKey, $isSuspended, 'bcc_trust', 60);

        return !$isSuspended;
    }

    // can_edit_post() removed — misleadingly named (checks PeepSo
    // ownership, not WP edit_post capability). Zero callers found.
    // Use owns_page() directly.

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
