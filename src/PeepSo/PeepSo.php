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
        // cannot leave a stale lock.
        $lock_key = 'bcc_po_' . $page_id;
        if (!\BCC\Core\DB\AdvisoryLock::acquire($lock_key, 0)) {
            // Another request already holds the lock and is resolving this
            // page's owner. Do NOT cache a WRONG null here — downstream
            // (Permissions::owns_page, dispute/card gates) treats null as
            // "no owner" and would deny the real owner. Resolve directly
            // this once (we forgo the stampede dedup for this request only);
            // the lock holder still populates the shared object cache. On
            // the default Redis-less setup wp_cache is request-scoped, so a
            // direct resolve is the only way to get the correct owner here.
            $owner = ServiceLocator::resolvePageOwnerResolver()->getPageOwner($page_id);
            return self::$cache[$page_id] = ($owner ?: null);
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