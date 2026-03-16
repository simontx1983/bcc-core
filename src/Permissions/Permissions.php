<?php

namespace BCC\Core\Permissions;

use BCC\Core\PeepSo\PeepSo;

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
     * Whether the given user owns a PeepSo page.
     *
     * Uses the PeepSo adapter for lookup, then falls back to WP post author.
     */
    public static function owns_page(int $page_id, int $user_id = 0): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id || !$page_id) {
            return false;
        }

        $owner_id = PeepSo::get_page_owner($page_id);

        if ($owner_id !== null) {
            return $owner_id === $user_id;
        }

        // Fallback: WP post author for peepso-page post type.
        $post = get_post($page_id);

        return $post
            && $post->post_type === 'peepso-page'
            && (int) $post->post_author === $user_id;
    }
}
