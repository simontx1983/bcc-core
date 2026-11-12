<?php

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only metrics from the MySQL server and wp_options table.
 *
 * Encapsulates the raw $wpdb calls that the /system/health REST
 * endpoint needs. Every method returns a scalar or a plain value
 * object — no $wpdb leakage outside this class.
 *
 * All queries are bounded aggregates / fixed-shape SHOW STATUS
 * commands so they are safe to invoke on a large production
 * wp_options table.
 */
final class DbMetricsRepository
{
    /**
     * Count wp_options rows whose option_name starts with $prefix.
     *
     * An escaped literal-prefix LIKE: an index range scan on option_name,
     * bounded to the prefix. ⚠ F-126: this was a `[prefix, prefix.'~')`
     * range, which under utf8mb4_unicode_ci / unicode_520_ci (`~` sorts
     * below digits and letters) counted none of the rate-limit rows, so
     * /system/health reported ~0 while the table grew.
     */
    public static function countOptionsWithPrefix(string $prefix): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix) . '%'
        ));
    }

    /**
     * Value of a MySQL server status variable.
     *
     * Wraps `SHOW GLOBAL STATUS LIKE ...` which works on MySQL 5.7
     * and 8.x (information_schema.GLOBAL_STATUS was removed in 8.0).
     *
     * Variable name is whitelisted — only [A-Za-z0-9_] allowed — to
     * keep the LIKE argument injection-free even though $wpdb->prepare
     * is used.
     */
    public static function showGlobalStatusInt(string $variable): int
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $variable) !== 1) {
            return 0;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SHOW GLOBAL STATUS LIKE %s",
            $variable
        ));
        return $row ? (int) $row->Value : 0;
    }

    /**
     * Value of a MySQL server system variable (e.g. @@max_connections).
     *
     * Variable name is whitelisted; interpolated (not prepared)
     * because MySQL disallows parameter markers in @@var references.
     */
    public static function showSystemVariableInt(string $variable): int
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $variable) !== 1) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var("SELECT @@{$variable}");
    }
}
