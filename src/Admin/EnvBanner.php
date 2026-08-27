<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * Renders a colored environment banner on every wp-admin page so an
 * operator cannot confuse prod and staging during a destructive action.
 *
 * Reads the BCC_ENV constant (set in wp-config.php). Recognized values:
 *   'production' — red   (CANONICAL)
 *   'prod'       — red   (legacy alias, identical rendering)
 *   'staging'    — yellow
 *   'dev'        — neutral
 *   'local'      — neutral
 *   anything else / undefined — yellow with an explicit "ENV unknown" label
 *
 * ── WHY 'production' IS CANONICAL AND 'prod' IS ONLY AN ALIAS ───────────
 * This banner was the ONLY component that read BCC_ENV, so 'prod' looked
 * like the whole vocabulary. It is not. The daily site-url-guard
 * (umbrella scripts/site-url-probe.sh) compares the value this host
 * reports — verbatim, via {@see \BCC\Core\Rest\IdentityEndpoint} — against
 * the literal 'production'. So a host configured 'prod' satisfied the
 * banner and FAILED the guard, and a host configured 'production'
 * satisfied the guard and showed "ENV UNKNOWN". There was no value a
 * production host could hold that satisfied both.
 *
 * 'production' is therefore the one canonical token for deployed
 * production, matching the guard, the workflow and the docs. 'prod' is
 * kept solely so an older wp-config.php does not regress to an unknown
 * banner mid-rollout; it must not be used for new configuration.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DO ─────────────────────────────────
 * It does not normalize, rewrite or canonicalize the configured value.
 * The alias exists in the DISPLAY switch only. The identity endpoint keeps
 * returning exactly what wp-config.php holds, because the guard's whole
 * purpose is to detect a host whose configuration disagrees with what the
 * caller believes — and a value quietly rewritten on the way out is a
 * disagreement made invisible.
 *
 * Matching stays case-sensitive: 'Production' and 'Live Site' are unknown,
 * loudly, rather than being guessed at.
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
            // 'production' is canonical; 'prod' is the legacy alias and
            // falls through so the two render byte-identically. Keeping the
            // existing PROD label means a host already on 'prod' sees no
            // change at all when the alias is eventually retired.
            case 'production':
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
