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
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_bcc_rl_') . '%'
));

// Clean up wallet-challenge transients.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_transient_bcc_wc_') . '%'
));

// Remove scheduled cron events.
wp_clear_scheduled_hook('bcc_core_rl_cleanup');

