<?php

namespace BCC\Core\PeepSo;

use BCC\Core\ServiceLocator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adapter for PeepSo page data.
 *
 * Resolves page ownership via the PageOwnerResolverInterface contract
 * (provided by bcc-trust-engine).  Falls back to WP post author if
 * no resolver is registered.
 */
final class PeepSo
{
    /**
     * Resolve the owner (user ID) of a PeepSo page.
     *
     * @return int|null  Owner user ID, or null if not found.
     */
    /** @var array<int, int|null> Resolved page owners, keyed by page ID. */
    private static array $cache = [];

    public static function get_page_owner(int $page_id): ?int
    {
        if (array_key_exists($page_id, self::$cache)) {
            return self::$cache[$page_id];
        }

        // NullPageOwnerResolver falls back to WP post_author internally,
        // so no separate fallback path is needed here.
        // Check WP object cache first (shared across FPM workers, invalidated on writes).
        $cache_key = 'bcc_page_owner_' . $page_id;
        $cached = wp_cache_get($cache_key, 'bcc_peepso');
        if ($cached !== false) {
            return self::$cache[$page_id] = ($cached ?: null);
        }

        $resolver = ServiceLocator::resolvePageOwnerResolver();
        $owner    = $resolver->getPageOwner($page_id);
        $result   = $owner ?: null;

        wp_cache_set($cache_key, $result ?? 0, 'bcc_peepso', 60);

        return self::$cache[$page_id] = $result;
    }

    /**
     * Invalidate the cached page-owner for a specific page.
     *
     * Call after ownership changes (page transfer, deletion) to ensure
     * subsequent lookups hit the database.
     */
    public static function invalidate(int $pageId): void
    {
        unset(self::$cache[$pageId]);
        wp_cache_delete('bcc_page_owner_' . $pageId, 'bcc_peepso');
    }
}