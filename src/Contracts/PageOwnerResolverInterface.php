<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the owner (user ID) of a PeepSo page.
 *
 * The trust engine owns this resolution because it maintains the
 * authoritative mapping across multiple PeepSo table variants
 * with caching and fallback chains.  Consumer plugins call this
 * interface via ServiceLocator instead of querying PeepSo tables.
 */
interface PageOwnerResolverInterface
{
    /**
     * @param int $pageId PeepSo page ID (wp_posts.ID where post_type = 'peepso-page').
     * @return int Owner user ID, or 0 if not found.
     */
    public function getPageOwner(int $pageId): int;

    /**
     * Inverse lookup: given a user ID, return their primary page ID.
     *
     * Checks trust_page_scores first (authoritative), falls back to
     * wp_posts for peepso-page CPT.
     *
     * @param int $userId WordPress user ID.
     * @return int Page ID, or 0 if not found.
     */
    public function getPageForOwner(int $userId): int;
}
