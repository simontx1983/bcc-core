<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * One shared look for every BCC wp-admin page.
 *
 * Any admin request whose `?page=` starts with `bcc-` gets the
 * `bcc-admin` body class and assets/css/bcc-admin.css — the light
 * "paper" palette lifted from the frontend tokens. The stylesheet
 * defines the `--bcc-*` tokens the bcc-trust Forge sheet
 * (admin-dashboard.css) already consumes, so Forge components and
 * WP-native pages (.widefat, .notice, .nav-tab, .button) read one
 * palette. Inline styles in page renderers reference the same
 * `var(--bcc-*)` tokens instead of hex literals.
 */
final class AdminTheme
{
    public const BODY_CLASS = 'bcc-admin';
    public const HANDLE     = 'bcc-admin-theme';

    public static function register(): void
    {
        add_filter('admin_body_class', [self::class, 'bodyClass']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function isBccPage(): bool
    {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        return str_starts_with($page, 'bcc-');
    }

    /**
     * @param string $classes Space-separated body classes from WP.
     */
    public static function bodyClass($classes): string
    {
        $classes = (string) $classes;
        return self::isBccPage() ? $classes . ' ' . self::BODY_CLASS : $classes;
    }

    public static function enqueue(): void
    {
        if (!self::isBccPage()) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            plugins_url('assets/css/bcc-admin.css', dirname(__DIR__, 2) . '/bcc-core.php'),
            [],
            defined('BCC_CORE_VERSION') ? (string) constant('BCC_CORE_VERSION') : null
        );
    }
}
