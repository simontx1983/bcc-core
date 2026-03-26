<?php

namespace BCC\Core\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralised table-name resolver.
 *
 * All BCC plugins should use `DB::table('disputes')` instead of
 * hard-coding `$wpdb->prefix . 'bcc_disputes'` everywhere.
 *
 * Child plugins register their table names via the
 * `bcc.resolve.table_name` filter. bcc-core has no knowledge of
 * which tables exist in any downstream plugin.
 */
final class DB
{
    /** @var array<string, string> Request-level cache of resolved table names. */
    private static array $cache = [];

    /**
     * Resolve a fully-qualified table name.
     *
     * Resolution order:
     *  1. Return from static cache if already resolved this request.
     *  2. Ask child plugins via `bcc.resolve.table_name` filter.
     *  3. Fall back to the convention: `{wp_prefix}bcc_{$name}`.
     *
     * @param string $name  Short name, e.g. 'disputes', 'trust_votes'.
     * @return string  Full table name including WP prefix.
     */
    public static function table(string $name): string
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $resolved = apply_filters('bcc.resolve.table_name', null, $name);

        if (is_string($resolved) && $resolved !== '') {
            self::$cache[$name] = $resolved;
            return $resolved;
        }

        global $wpdb;
        self::$cache[$name] = $wpdb->prefix . 'bcc_' . $name;

        return self::$cache[$name];
    }

    /**
     * Flush the table-name cache.
     *
     * Useful in unit tests or after table creation during activation.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
