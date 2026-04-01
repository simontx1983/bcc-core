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
        $resolver = ServiceLocator::resolvePageOwnerResolver();
        $owner    = $resolver->getPageOwner($page_id);

        return self::$cache[$page_id] = $owner ?: null;
    }
}