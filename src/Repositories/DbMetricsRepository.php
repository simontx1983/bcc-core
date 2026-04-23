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
     * Count wp_options rows whose option_name falls in [start, end).
     *
     * Range-scan (not LIKE) so the index is usable and the scan is
     * bounded to the rate-limiter key range.
     */
    public static function countOptionsInRange(string $rangeStart, string $rangeEnd): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name >= %s AND option_name < %s",
            $rangeStart,
            $rangeEnd
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
