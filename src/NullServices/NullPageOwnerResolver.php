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
        $pages = get_posts([
            'author'         => $userId,
            'post_type'      => 'peepso-page',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
        ]);

        return !empty($pages) ? (int) $pages[0] : 0;
    }
}
