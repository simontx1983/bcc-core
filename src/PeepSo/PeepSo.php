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
 * (provided by bcc-trust). Falls back to WP post author if no resolver
 * is registered.
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

        // Stampede lock: MySQL GET_LOCK is atomic across all DB sessions
        // and auto-releases on connection close, so a crashed worker
        // cannot leave a stale lock. Losers return null for this request
        // rather than piling onto the DB.
        $lock_key = 'bcc_po_' . $page_id;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lock_key, 0)) {
            return self::$cache[$page_id] = null;
        }

        try {
            $resolver = ServiceLocator::resolvePageOwnerResolver();
            $owner    = $resolver->getPageOwner($page_id);
            $result   = $owner ?: null;

            wp_cache_set($cache_key, $result ?? 0, self::CACHE_GROUP, self::CACHE_TTL);

            return self::$cache[$page_id] = $result;
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock_key);
        }
    }
}