<?php
/**
 * BCC Core – Uninstall handler.
 *
 * Runs only when the plugin is deleted via the WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('bcc_core_version');
