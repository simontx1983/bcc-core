<?php
/**
 * Plugin Name: Blue Collar Crypto – Core
 * Description: Shared infrastructure for the BCC plugin ecosystem: permissions, PeepSo adapter, DB helpers, caching, logging, and utilities. Production requires a persistent object cache (Redis/Memcached) for rate limiting and API budget enforcement.
 * Version:     1.0.0
 * Author:      Blue Collar Labs LLC
 * Text Domain: bcc-core
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Extension Check ─────────────────────────────────────────────
// GMP must be checked BEFORE defining BCC_CORE_VERSION so downstream
// plugins that gate on defined('BCC_CORE_VERSION') correctly see core
// as unavailable when the autoloader cannot load.

if (!extension_loaded('gmp')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Blue Collar Crypto – Core requires the GMP PHP extension. Please enable it in your php.ini.', 'bcc-core');
        echo '</p></div>';
    });
    return;
}

// ── Composer Autoloader ──────────────────────────────────────────

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ── Constants (defined only after core is fully functional) ──────

define('BCC_CORE_VERSION', '1.0.0');
define('BCC_CORE_PATH', plugin_dir_path(__FILE__));
define('BCC_CORE_URL', plugin_dir_url(__FILE__));

// ── Rate-limit row cleanup ──────────────────────────────────────
// Throttle's DB fallback writes rows to wp_options that never expire.
// This daily cron garbage-collects expired entries.

add_action('bcc_core_daily_cleanup', function () {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\\_bcc\\_rl\\_%'
           AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < UNIX_TIMESTAMP()"
    );
});

add_action('init', function () {
    if (!wp_next_scheduled('bcc_core_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'bcc_core_daily_cleanup');
    }
}, 99);

