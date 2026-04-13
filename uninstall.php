<?php
/**
 * BCC Core – Uninstall handler.
 *
 * Runs only when the plugin is deleted via the WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up rate-limit rows stored as options.
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_bcc\_rl\_%'");

// Clean up wallet-challenge transients.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bcc\_wc\_%'");

// Remove scheduled cron events.
wp_clear_scheduled_hook('bcc_core_rl_cleanup');
wp_clear_scheduled_hook('bcc_core_daily_cleanup');

