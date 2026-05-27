<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * Renders a colored environment banner on every wp-admin page so an
 * operator cannot confuse prod and staging during a destructive action.
 *
 * Reads the BCC_ENV constant (set in wp-config.php). Recognized values:
 *   'prod'    — red
 *   'staging' — yellow
 *   'dev'     — neutral
 *   anything else / undefined — yellow with an explicit "ENV unknown" label
 */
final class EnvBanner
{
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function render(): void
    {
        $env = defined('BCC_ENV') && is_string(BCC_ENV) ? BCC_ENV : '';

        switch ($env) {
            case 'prod':
                $cssClass = 'notice-error';
                $label    = 'PROD';
                break;
            case 'staging':
                $cssClass = 'notice-warning';
                $label    = 'STAGING';
                break;
            case 'dev':
            case 'local':
                $cssClass = 'notice-info';
                $label    = strtoupper($env);
                break;
            default:
                $cssClass = 'notice-warning';
                $label    = 'ENV UNKNOWN — set BCC_ENV in wp-config.php';
                break;
        }

        printf(
            '<div class="notice %1$s" style="margin:0 0 8px 0;border-left-width:6px;"><p><strong>%2$s</strong></p></div>',
            esc_attr($cssClass),
            esc_html($label)
        );
    }
}
