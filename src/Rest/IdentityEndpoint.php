<?php

declare(strict_types=1);

namespace BCC\Core\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /bcc/v1/internal/identity — which environment does this DATABASE think
 * it is?
 *
 * ── WHY BOTH THE ROWS AND THE CONSTANTS ─────────────────────────────────
 * When wp-config.php defines WP_SITEURL/WP_HOME, WordPress IGNORES
 * wp_options.siteurl and wp_options.home. The site then serves correctly
 * while those rows name a different environment entirely — which is exactly
 * how a staging-flavoured database restore sat inside production undetected.
 * The constants did not prevent the drift. They MASKED it.
 *
 * The drift therefore IS the delta between the two blocks, so this endpoint
 * returns both. A caller can report "constants say cms., rows say stage." in
 * one line instead of inferring it from a single value.
 *
 * ── HOW THE ROWS ARE READ ───────────────────────────────────────────────
 * `$wpdb->get_var()` on a direct query. NEVER `get_option()`:
 *
 *   - `get_option('siteurl')` runs through the `pre_option_siteurl` filter,
 *     which is the very mechanism WordPress uses to return WP_SITEURL when
 *     the constant is defined. It would hand back the CONSTANT, and this
 *     endpoint would be comparing a value to itself and always agreeing.
 *   - It is also served from the object cache, which can outlive the row.
 *
 * Reading the raw row is the entire point. Anything more convenient defeats
 * it silently, which is the worst way for a guard to fail.
 *
 * ── WHAT IS DELIBERATELY NOT RETURNED ───────────────────────────────────
 * The table prefix and the options-table NAME. Leaking the WordPress table
 * prefix over a network endpoint hands out the one piece of schema knowledge
 * most SQL-injection payloads need in order to be useful. Callers get
 * `options_table_count` instead: enough to detect ambiguity (>1 means
 * multisite, two installs sharing a database, or a plugin table that happens
 * to end in "options" — all of which need a human on-host), and of no use to
 * an attacker.
 *
 * ── AUTH ────────────────────────────────────────────────────────────────
 * Reuses `X-Bcc-Internal` verified against the `BCC_INTERNAL_CRON_SECRET`
 * constant (§11) — the same scheme
 * {@see \BCC\Trust\Onchain\REST\IndexerTickEndpoint} uses for the Vercel cron
 * relay. No second token scheme is introduced.
 *
 * The check lives in `permission_callback`, NOT in the handler. A
 * `permission_callback` receives the WP_REST_Request and can read custom
 * headers perfectly well, so there is no technical reason to defer it — and
 * `'__return_true'` would advertise this route as PUBLIC to REST discovery,
 * to security plugins, and to anyone reading the route table.
 *
 * ── CACHING ─────────────────────────────────────────────────────────────
 * A cached identity response reports healthy long after the rows change. A
 * guard that caches is a guard that lies, and this one would lie
 * confidently. LiteSpeed caches REST responses on this site (`ttl_rest`),
 * so the plugin-level exclusion is required in addition to the headers, and
 * it is set on the permission path too — so even a 401 cannot be cached and
 * replayed.
 *
 * @see docs/hosting.md (umbrella) — three-host topology and the drift story
 */
final class IdentityEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/internal/identity';

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /**
     * Shared-secret gate. Runs before the handler and before anything reads
     * the database.
     *
     * @return true|WP_Error
     */
    public static function authorize(WP_REST_Request $request)
    {
        // Nothing about this response may be cached — including a refusal.
        self::denyCaching();

        // Fail closed on misconfiguration. Without a pinned secret on both
        // ends we cannot safely answer at all, and answering "unauthorized"
        // would misdescribe a deployment problem as a caller problem.
        $expected = self::expectedSecret();
        if ($expected === '') {
            \BCC\Core\Log\Logger::error('[IdentityEndpoint] BCC_INTERNAL_CRON_SECRET not configured');

            return new WP_Error(
                'bcc_internal_secret_not_configured',
                'Internal secret not configured on this host.',
                ['status' => 500]
            );
        }

        $provided = (string) $request->get_header('x-bcc-internal');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return new WP_Error(
                'bcc_unauthorized',
                'Invalid or missing internal secret.',
                ['status' => 401]
            );
        }

        return true;
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        // `$wpdb->options` is the resolved table name; the option_name values
        // are literals, so nothing caller-controlled is interpolated.
        $rowSiteurl = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'siteurl'
        ));
        $rowHome = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'home'
        ));

        $optionsTableCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%options'"
        );

        $payload = [
            // The caller cross-checks this against the environment it BELIEVES
            // it is probing. That single comparison defangs every misrouting
            // failure — a wrong repo variable, a bad dispatch input, a
            // fallback that silently chose the other host — because the host
            // states its own identity instead of the caller assuming it.
            'env' => defined('BCC_ENV') && is_string(BCC_ENV) && BCC_ENV !== ''
                ? BCC_ENV
                : 'unknown',

            // null distinguishes "row absent" from "row empty". Both are
            // drift, but they are different repairs.
            'rows' => [
                'siteurl' => $rowSiteurl === null ? null : (string) $rowSiteurl,
                'home'    => $rowHome === null ? null : (string) $rowHome,
            ],
            'constants' => [
                'siteurl' => defined('WP_SITEURL') ? (string) constant('WP_SITEURL') : null,
                'home'    => defined('WP_HOME') ? (string) constant('WP_HOME') : null,
            ],
            'options_table_count' => $optionsTableCount,
        ];

        $response = new WP_REST_Response($payload, 200);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Tell LiteSpeed not to cache this response.
     *
     * The `Cache-Control` header alone is not sufficient: LSCWP decides at the
     * server layer and can cache a REST response regardless of what the
     * application asked for, which is why the plugin action exists.
     */
    private static function denyCaching(): void
    {
        do_action('litespeed_control_set_nocache', 'bcc identity probe must never be cached');

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }

    private static function expectedSecret(): string
    {
        return defined('BCC_INTERNAL_CRON_SECRET')
            ? (string) constant('BCC_INTERNAL_CRON_SECRET')
            : '';
    }
}
