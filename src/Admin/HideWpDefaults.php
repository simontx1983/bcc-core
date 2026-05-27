<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * Hides the WordPress default admin menus that BCC's headless setup
 * never uses, so they don't sit between operators and their actual
 * tools.
 *
 * Two-engineer audit follow-up. Phillip + Tialuxe share a wp-admin
 * for a headless platform — there is no use case for Posts /
 * Comments / Media / Pages (the CPT) / Appearance / Settings →
 * Reading / Discussion / Permalinks. Hiding them reduces "which
 * menu has what" cognitive load without removing capabilities (a
 * super-admin can still navigate to any URL directly).
 *
 * Filter: `bcc_hide_wp_defaults_enabled` — return false to disable
 * the hiding behavior entirely. `bcc_hide_wp_defaults_menus` — return
 * a modified array of top-level slugs to skip / add to. Both default
 * to the headless-platform expectation.
 *
 * Note: this hides MENU ITEMS only. It does not remove capabilities
 * — anyone with `manage_options` can still hit /wp-admin/edit.php
 * directly if they really want to. That's intentional; the goal is
 * reduced clutter, not access control.
 */
final class HideWpDefaults
{
    public static function register(): void
    {
        // Late on admin_menu so we run after every plugin that
        // registers menus (otherwise some defaults aren't on the
        // menu yet when we try to remove them).
        add_action('admin_menu', [self::class, 'hide'], 999);
    }

    public static function hide(): void
    {
        if (!apply_filters('bcc_hide_wp_defaults_enabled', true)) {
            return;
        }

        // Top-level menu slugs to hide.
        $topLevel = apply_filters('bcc_hide_wp_defaults_menus', [
            'edit.php',                       // Posts
            'edit-comments.php',              // Comments
            'upload.php',                     // Media
            'edit.php?post_type=page',        // Pages (WP CPT, not peepso-page)
            'themes.php',                     // Appearance
        ]);
        if (is_array($topLevel)) {
            foreach ($topLevel as $slug) {
                remove_menu_page((string) $slug);
            }
        }

        // Settings sub-pages that don't apply to a headless setup.
        // Keep Settings → General (site URL, env), Privacy, Tools →
        // Site Health, Tools → Personal Data export/erase. Those are
        // load-bearing.
        $settingsSub = apply_filters('bcc_hide_wp_defaults_settings_sub', [
            'options-reading.php',
            'options-discussion.php',
            'options-permalink.php',
            'options-writing.php',
        ]);
        if (is_array($settingsSub)) {
            foreach ($settingsSub as $sub) {
                remove_submenu_page('options-general.php', (string) $sub);
            }
        }
    }
}
