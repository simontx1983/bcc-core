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

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid table name segment: {$name}");
        }

        // SECURITY: Table names are resolved via convention only.
        // The bcc.resolve.table_name filter was removed because it allowed
        // any co-installed plugin to redirect BCC queries to arbitrary
        // tables (wp_users, wp_options) — a site-breaking injection surface.
        global $wpdb;
        self::$cache[$name] = $wpdb->prefix . 'bcc_' . $name;

        return self::$cache[$name];
    }

}
