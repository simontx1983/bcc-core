<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\PageOwnerResolverInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * Fails CLOSED outside the one post type the platform actually uses for
 * page ownership (peepso-page). The prior implementation returned
 * post_author for ANY post type — meaning during the bcc-trust
 * null-fallback window, any WP author could pass Permissions::owns_page()
 * for any post they authored (regular posts, attachments, arbitrary
 * plugin CPTs) and reach page-owner-gated REST routes (submit-dispute,
 * etc.) with pages they don't actually own.
 */
final class NullPageOwnerResolver implements PageOwnerResolverInterface
{
    public function getPageOwner(int $pageId): int
    {
        $post = get_post($pageId);
        if (!$post || $post->post_type !== 'peepso-page') {
            return 0;
        }
        return (int) $post->post_author;
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
