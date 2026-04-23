<?php
/**
 * BCC Core – Uninstall handler.
 *
 * Runs only when the plugin is deleted via the WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up rate-limit rows stored as options. Both prefixes are covered:
//   _bcc_rl_*           — Throttle's options-backed sliding window.
//   _transient_bcc_rl_* — trust-engine RateLimiter's options-backed
//                         sliding window (with paired _transient_timeout_*
//                         rows that WP's own GC would handle, but which we
//                         also reap here so uninstall leaves no residue).
//
// The bcc_core_rl_cleanup cron (cleared below) scans these same ranges
// periodically, so under normal operation wp_options never accumulates
// expired rate-limit rows. This uninstall sweep covers the rows still
// live at the time the operator removes the plugin.
global $wpdb;

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_bcc_rl_') . '%'
));

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_transient_bcc_rl_') . '%'
));

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_transient_timeout_bcc_rl_') . '%'
));

// Clean up wallet-challenge transients.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_transient_bcc_wc_') . '%'
));

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('_transient_timeout_bcc_wc_') . '%'
));

// Remove scheduled cron events.
wp_clear_scheduled_hook('bcc_core_rl_cleanup');

