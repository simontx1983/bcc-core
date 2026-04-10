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
     * Soft-depends on bcc-trust-engine: returns true if trust-engine inactive.
     * Result is cached for 60 seconds per user.
     */
    public static function is_not_suspended(?int $user_id = null): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $cacheKey = "bcc_user_suspended_{$user_id}";
        $cached = wp_cache_get($cacheKey, 'bcc_trust');
        if ($cached !== false) {
            return !$cached;
        }

        // Delegates to the trust-engine implementation (or NullTrustReadService
        // which returns false) — no concrete coupling required.
        $isSuspended = ServiceLocator::resolveTrustReadService()->isSuspended($user_id);
        wp_cache_set($cacheKey, $isSuspended, 'bcc_trust', 60);

        return !$isSuspended;
    }

    /**
     * Whether the user can edit the given post (delegates to owns_page).
     */
    public static function can_edit_post(int $post_id, ?int $user_id = null): bool
    {
        return self::owns_page($post_id, $user_id ?: get_current_user_id());
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
