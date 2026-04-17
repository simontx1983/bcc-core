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

    private const CACHE_GROUP = 'bcc_peepso';
    private const CACHE_TTL   = 300; // 5 minutes

    public static function get_page_owner(int $page_id): ?int
    {
        if (array_key_exists($page_id, self::$cache)) {
            return self::$cache[$page_id];
        }

        $cache_key = 'bcc_page_owner_' . $page_id;
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return self::$cache[$page_id] = ($cached ?: null);
        }

        // Stampede lock: wp_cache_add is atomic — only one process wins.
        // Losers return null for this request rather than piling onto the DB.
        $lock_key = $cache_key . '_lock';
        if (!\wp_cache_add($lock_key, 1, self::CACHE_GROUP, 5)) {
            return self::$cache[$page_id] = null;
        }

        $resolver = ServiceLocator::resolvePageOwnerResolver();
        $owner    = $resolver->getPageOwner($page_id);
        $result   = $owner ?: null;

        wp_cache_set($cache_key, $result ?? 0, self::CACHE_GROUP, self::CACHE_TTL);
        wp_cache_delete($lock_key, self::CACHE_GROUP);

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
        wp_cache_delete('bcc_page_owner_' . $pageId, self::CACHE_GROUP);
    }
}