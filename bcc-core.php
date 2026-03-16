<?php
/**
 * Plugin Name: Blue Collar Crypto – Core
 * Description: Shared infrastructure for the BCC plugin ecosystem: permissions, PeepSo adapter, DB helpers, caching, logging, and utilities.
 * Version:     1.0.0
 * Author:      Blue Collar Labs LLC
 * Text Domain: bcc-core
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BCC_CORE_VERSION', '1.0.0');
define('BCC_CORE_PATH', plugin_dir_path(__FILE__));
define('BCC_CORE_URL', plugin_dir_url(__FILE__));

// ── PSR-4 Autoloader ────────────────────────────────────────────────────────

spl_autoload_register(function (string $class): void {
    $prefix = 'BCC\\Core\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = BCC_CORE_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Bootstrap ──────────────────────────────────────────────────────────────────

add_action('plugins_loaded', function (): void {
    /**
     * Fires after BCC Core is fully loaded.
     * Other BCC plugins should hook here instead of plugins_loaded
     * to guarantee core utilities are available.
     */
    do_action('bcc_core_loaded');
}, 5);

// ── Activation / Uninstall ─────────────────────────────────────────────────────

register_activation_hook(__FILE__, function (): void {
    update_option('bcc_core_version', BCC_CORE_VERSION);
});

