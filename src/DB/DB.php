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
        // Cache key includes $wpdb->prefix so multisite blog-switches
        // don't return a stale table name from a different blog's prefix.
        global $wpdb;
        $cacheKey = $wpdb->prefix . $name;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid table name segment: {$name}");
        }

        // SECURITY: Table names are resolved via convention only.
        // The bcc.resolve.table_name filter was removed because it allowed
        // any co-installed plugin to redirect BCC queries to arbitrary
        // tables (wp_users, wp_options) — a site-breaking injection surface.
        self::$cache[$cacheKey] = $wpdb->prefix . 'bcc_' . $name;

        return self::$cache[$cacheKey];
    }

    // ── Typed query helpers ─────────────────────────────────────────────

    /**
     * Execute a prepared query and return a single row, or null.
     *
     * Use for optional lookups where "not found" is a valid outcome.
     * PHPStan sees the return type as `?\stdClass`, so callers must
     * null-check before accessing properties — which is the whole point.
     *
     * @param string $query  SQL with %s/%d placeholders.
     * @param mixed  ...$args  Values for prepare().
     * @return \stdClass|null
     */
    public static function getRow(string $query, mixed ...$args): ?\stdClass
    {
        global $wpdb;
        if (!empty($args)) {
            $query = $wpdb->prepare($query, ...$args);
        }
        return $wpdb->get_row($query) ?: null;
    }

    /**
     * Execute a prepared query and return a single row, or throw.
     *
     * Use for required lookups where "not found" indicates a system error
     * (e.g., fetching a dispute that was just confirmed to exist).
     *
     * @param string $query  SQL with %s/%d placeholders.
     * @param mixed  ...$args  Values for prepare().
     * @return \stdClass  Always non-null.
     * @throws \RuntimeException If the query returns no row.
     */
    public static function getRequiredRow(string $query, mixed ...$args): \stdClass
    {
        $row = self::getRow($query, ...$args);
        if ($row === null) {
            throw new \RuntimeException('Required DB row not found');
        }
        return $row;
    }

    /**
     * Execute a prepared query and return a single scalar value, or null.
     *
     * @param string $query  SQL with %s/%d placeholders.
     * @param mixed  ...$args  Values for prepare().
     * @return string|null  The raw scalar (always string from MySQL, or null).
     */
    public static function getVar(string $query, mixed ...$args): ?string
    {
        global $wpdb;
        if (!empty($args)) {
            $query = $wpdb->prepare($query, ...$args);
        }
        $val = $wpdb->get_var($query);
        return $val !== null ? (string) $val : null;
    }

    /**
     * Execute a prepared query and return all rows.
     *
     * @param string $query  SQL with %s/%d placeholders.
     * @param mixed  ...$args  Values for prepare().
     * @return list<\stdClass>  Empty array if no results.
     */
    public static function getResults(string $query, mixed ...$args): array
    {
        global $wpdb;
        if (!empty($args)) {
            $query = $wpdb->prepare($query, ...$args);
        }
        return $wpdb->get_results($query) ?: [];
    }

    /**
     * Execute a prepared query and return a single column as a flat array.
     *
     * @param string $query  SQL with %s/%d placeholders.
     * @param mixed  ...$args  Values for prepare().
     * @return list<string>  Empty array if no results.
     */
    public static function getCol(string $query, mixed ...$args): array
    {
        global $wpdb;
        if (!empty($args)) {
            $query = $wpdb->prepare($query, ...$args);
        }
        return $wpdb->get_col($query) ?: [];
    }
}
