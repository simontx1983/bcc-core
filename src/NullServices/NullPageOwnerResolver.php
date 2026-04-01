<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\PageOwnerResolverInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust-engine is not active.
 *
 * Falls back to WP post_author for page ownership resolution.
 */
final class NullPageOwnerResolver implements PageOwnerResolverInterface
{
    public function getPageOwner(int $pageId): int
    {
        $post = get_post($pageId);
        return $post ? (int) $post->post_author : 0;
    }

    public function getPageForOwner(int $userId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_author = %d AND post_type = 'peepso-page' AND post_status = 'publish'
             LIMIT 1",
            $userId
        ));
    }
}
