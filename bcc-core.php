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

// ── Composer Autoloader ──────────────────────────────────────────

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

