<?php
/**
 * Plugin Name: Blue Collar Crypto – Core
 * Description: Shared infrastructure for the BCC plugin ecosystem: permissions, PeepSo adapter, DB helpers, caching, logging, and utilities. Production requires a persistent object cache (Redis/Memcached) for rate limiting and API budget enforcement.
 * Version:     1.0.0
 * Author:      Blue Collar Labs LLC
 * Text Domain: bcc-core
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Extension Check ─────────────────────────────────────────────
// GMP must be checked BEFORE defining BCC_CORE_VERSION so downstream
// plugins that gate on defined('BCC_CORE_VERSION') correctly see core
// as unavailable when the autoloader cannot load.

if (!extension_loaded('gmp')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Blue Collar Crypto – Core requires the GMP PHP extension. Please enable it in your php.ini.', 'bcc-core');
        echo '</p></div>';
    });
    return;
}

// ── Composer Autoloader ──────────────────────────────────────────

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ── Constants (defined only after core is fully functional) ──────

define('BCC_CORE_VERSION', '1.0.0');
define('BCC_CORE_PATH', plugin_dir_path(__FILE__));
define('BCC_CORE_URL', plugin_dir_url(__FILE__));

// ── Rate-limiter readiness enforcement ──────────────────────────
// BCC requires a safe rate-limiter backend — either the trust-engine's
// atomic RateLimiter OR a persistent object cache (Redis / Memcached).
// Throttle::isReady() is the single source of truth; Throttle::allow()
// FAILS CLOSED (denies every action) when isReady() returns false so a
// missing backend cannot silently disengage abuse protection.
//
// This notice runs late on plugins_loaded so all BCC plugins — including
// bcc-trust-engine, which provides the preferred RateLimiter class —
// have had a chance to register before we probe the environment.

add_action('plugins_loaded', function (): void {
    if (\BCC\Core\Security\Throttle::isReady()) {
        return;
    }

    // Log once per request so the problem shows up in whatever log
    // aggregation the operator uses, not just the admin UI.
    if (class_exists('\\BCC\\Core\\Log\\Logger')) {
        \BCC\Core\Log\Logger::error(
            '[bcc-core] Rate limiter NOT ready — denying all throttled actions. ' .
            'Install Redis / Memcached or activate bcc-trust-engine (RateLimiter).'
        );
    }
}, 100);

add_action('admin_notices', function () {
    if (\BCC\Core\Security\Throttle::isReady()) {
        return;
    }
    // Blocking red notice — intentionally not dismissible. The deny-all
    // fail-closed behaviour in Throttle::allow means EVERY rate-limited
    // action (disputes, voting, wallet verification, report-user, etc.)
    // is returning 429 until the operator provisions a backend.
    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
        esc_html('Blue Collar Crypto — Rate limiter offline:'),
        esc_html(
            'BCC requires Redis or a persistent object cache for safe rate limiting. '
            . 'No backend is currently available, so all throttled actions (disputes, voting, '
            . 'wallet verification, user reports) are being DENIED by default. Install Redis / '
            . 'Memcached or activate bcc-trust-engine to restore service.'
        )
    );
});

// ── ServiceLocator freeze ───────────────────────────────────────
// After all plugins have loaded, freeze the ServiceLocator cache so
// late-registered filters cannot replace already-resolved services.

add_action('plugins_loaded', [\BCC\Core\ServiceLocator::class, 'freeze'], PHP_INT_MAX);

// ── Cross-plugin suspension cache invalidation ─────────────────
// Trust-engine fires `bcc_user_suspension_changed` when a user's
// suspension status changes. This ensures Permissions picks it up
// immediately instead of waiting for the 60-second cache TTL.
\BCC\Core\Permissions\Permissions::registerHooks();

// ── Rate-limit row cleanup ──────────────────────────────────────
// Throttle's DB fallback and RateLimiter write rows to wp_options
// that never auto-expire. This hourly cron garbage-collects expired
// entries in bounded batches to avoid table-locking.

add_action('bcc_core_rl_cleanup', function () {
    // Advisory lock prevents overlapping runs when WP-Cron fires
    // multiple times (duplicate spawns, external cron + wp-cron race).
    if (!\BCC\Core\DB\AdvisoryLock::acquire('bcc_core_rl_cleanup', 0)) {
        return;
    }

    try {
        // Clean both prefixes: _bcc_rl_ (Throttle) and _transient_bcc_rl_ (RateLimiter).
        // Use range scan instead of LIKE wildcards to avoid full table scan.
        // Loop up to 10 times (10000 rows max per cron tick).
        $ranges = [
            ['_bcc_rl_',           '_bcc_rl_~'],
            ['_transient_bcc_rl_', '_transient_bcc_rl_~'],
        ];

        $totalDeleted = 0;
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            $iterations = 0;
            do {
                $deleted = \BCC\Core\Repositories\OptionCleanupRepository::deleteExpiredRange(
                    $rangeStart,
                    $rangeEnd,
                    1000
                );

                if ($deleted === null) {
                    \BCC\Core\Log\Logger::error('[bcc-core] rl_cleanup DELETE failed', [
                        'range' => $rangeStart,
                        'error' => \BCC\Core\Repositories\OptionCleanupRepository::lastError(),
                    ]);
                    break;
                }

                $totalDeleted += $deleted;
                $iterations++;
            } while ($deleted >= 1000 && $iterations < 10);
        }

        if ($totalDeleted > 0) {
            \BCC\Core\Log\Logger::info('[bcc-core] rl_cleanup', [
                'deleted' => $totalDeleted,
            ]);
        }
    } finally {
        \BCC\Core\DB\AdvisoryLock::release('bcc_core_rl_cleanup');
    }
});

add_filter('cron_schedules', function (array $schedules): array {
    if (!isset($schedules['bcc_thirty_minutes'])) {
        $schedules['bcc_thirty_minutes'] = [
            'interval' => 1800,
            'display'  => 'Every 30 Minutes (BCC RL Cleanup)',
        ];
    }
    return $schedules;
});

add_action('init', function () {
    // Run every 30 minutes — prevents row accumulation during traffic spikes.
    if (!wp_next_scheduled('bcc_core_rl_cleanup')) {
        wp_schedule_event(time(), 'bcc_thirty_minutes', 'bcc_core_rl_cleanup');
    }
}, 99);

// ── System health endpoint ─────────────────────────────────────
// Aggregates operational health data from all BCC plugins into a
// single admin-only endpoint for monitoring, alerting, and debugging.

add_action('rest_api_init', function () {
    register_rest_route('bcc/v1', '/system/health', [
        'methods'             => \WP_REST_Server::READABLE,
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => function () {
            $now = time();

            // ── Redis / object cache status ─────────────────────────────
            $hasRedis = wp_using_ext_object_cache();
            $cacheTestKey = 'bcc_health_probe_' . $now;
            wp_cache_set($cacheTestKey, 1, 'bcc_health', 10);
            $cacheWritable = wp_cache_get($cacheTestKey, 'bcc_health') === 1;
            wp_cache_delete($cacheTestKey, 'bcc_health');

            // ── Rate-limit row count in wp_options ──────────────────────
            // Use range scan instead of LIKE wildcards to avoid full table scan.
            $rlRowCount1 = \BCC\Core\Repositories\DbMetricsRepository::countOptionsInRange(
                '_bcc_rl_',
                '_bcc_rl_~'
            );
            $rlRowCount2 = \BCC\Core\Repositories\DbMetricsRepository::countOptionsInRange(
                '_transient_bcc_rl_',
                '_transient_bcc_rl_~'
            );
            $rlRowCount  = $rlRowCount1 + $rlRowCount2;

            // ── Service availability ────────────────────────────────────
            $services = [];
            $contracts = [
                'TrustReadService'       => \BCC\Core\Contracts\TrustReadServiceInterface::class,
                'ScoreContributor'       => \BCC\Core\Contracts\ScoreContributorInterface::class,
                'ScoreReadService'       => \BCC\Core\Contracts\ScoreReadServiceInterface::class,
                'DisputeAdjudicator'     => \BCC\Core\Contracts\DisputeAdjudicationInterface::class,
                'PageOwnerResolver'      => \BCC\Core\Contracts\PageOwnerResolverInterface::class,
                'WalletLinkRead'         => \BCC\Core\Contracts\WalletLinkReadInterface::class,
                'OnchainDataRead'        => \BCC\Core\Contracts\OnchainDataReadInterface::class,
            ];
            foreach ($contracts as $label => $contract) {
                $services[$label] = \BCC\Core\ServiceLocator::hasRealService($contract);
            }

            // ── Read model lag (oldest dirty page age) ──────────────────
            $rmDirtySet = wp_cache_get('bcc_rm_dirty_pages', 'bcc_rm_sync');
            $rmDirtyCount = 0;
            $rmMaxAgeSec  = 0;
            if (is_array($rmDirtySet) && !empty($rmDirtySet)) {
                $rmDirtyCount = count($rmDirtySet);
                $oldestFlag   = min($rmDirtySet);
                $rmMaxAgeSec  = $now - (int) $oldestFlag;
            }

            // ── DB active connections / threads running ──────────────────
            // Use SHOW STATUS (works on MySQL 5.7 and 8.x).
            // information_schema.GLOBAL_STATUS was removed in MySQL 8.0.
            $dbThreadsConnected = \BCC\Core\Repositories\DbMetricsRepository::showGlobalStatusInt('Threads_connected');
            $dbThreadsRunning   = \BCC\Core\Repositories\DbMetricsRepository::showGlobalStatusInt('Threads_running');
            $dbMaxConnections   = \BCC\Core\Repositories\DbMetricsRepository::showSystemVariableInt('max_connections');

            // ── Recalculation queue depth ────────────────────────────────
            // Resolved via RecalcQueueReadInterface so bcc-core does not
            // reach into trust-engine tables. The `source` field
            // disambiguates "0 = no backlog" from "0 = null object
            // because trust-engine is not wired". Dashboards MUST key
            // alerting on source, not on pending_pages alone.
            $recalcQueueReal = \BCC\Core\ServiceLocator::hasRealService(
                \BCC\Core\Contracts\RecalcQueueReadInterface::class
            );
            $recalcPending = \BCC\Core\ServiceLocator::resolveRecalcQueueRead()->pendingCount();
            $recalcSource  = $recalcQueueReal ? 'trust_engine' : 'unavailable';

            // ── Plugin-specific health (pulled via filter) ──────────────
            $pluginHealth = apply_filters('bcc_system_health', []);

            // ── Trust subsystem readiness ────────────────────────────────
            // These checks detect the "plugin active but system inert" state
            // that occurs when BCC_ENCRYPTION_KEY is missing or the trust
            // engine fails to register its ServiceLocator providers.
            $trustSubsystem = [
                'encryption_key_defined'   => defined('BCC_ENCRYPTION_KEY'),
                'trust_read_service_real'  => $services['TrustReadService'] ?? false,
                'dispute_adjudicator_real' => $services['DisputeAdjudicator'] ?? false,
            ];

            // System is degraded if any critical trust subsystem check fails.
            // NullTrustReadService::isSuspended() returns true, which locks
            // out all non-admin users — this is a platform-wide outage.
            $trustHealthy = $trustSubsystem['encryption_key_defined']
                         && $trustSubsystem['trust_read_service_real'];

            $overallStatus = $trustHealthy ? 'ok' : 'degraded';

            $response = rest_ensure_response([
                'status'    => $overallStatus,
                'timestamp' => gmdate('c'),
                'cache'     => [
                    'persistent_object_cache' => $hasRedis,
                    'cache_writable'          => $cacheWritable,
                    'rate_limiter_degraded'   => \BCC\Core\Security\Throttle::isDegraded(),
                    'rate_limit_rows'         => $rlRowCount,
                ],
                'read_model' => [
                    'dirty_pages'      => $rmDirtyCount,
                    'max_age_seconds'  => $rmMaxAgeSec,
                ],
                'recalculation' => [
                    'pending_pages' => $recalcPending,
                    'source'        => $recalcSource,
                ],
                'database' => [
                    'threads_connected' => $dbThreadsConnected,
                    'threads_running'   => $dbThreadsRunning,
                    'max_connections'   => $dbMaxConnections,
                    'utilization_pct'   => $dbMaxConnections > 0
                        ? round(($dbThreadsConnected / $dbMaxConnections) * 100, 1)
                        : 0,
                ],
                'services'  => $services,
                'trust_subsystem' => $trustSubsystem,
                'wp_cron'   => [
                    'disabled'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                ],
                'plugins'   => $pluginHealth,
            ]);
            $response->header('Cache-Control', 'private, max-age=60');
            return $response;
        },
    ]);

    // ── Public uptime probe ────────────────────────────────────────
    //
    // Returns HTTP 200 when the system is healthy, HTTP 503 when critical
    // subsystems are degraded. Safe to expose publicly — returns only a
    // minimal {status, checks} payload, no internal counts or DB state.
    //
    // Point an uptime monitor (UptimeRobot, Pingdom, Better Stack) at
    // /wp-json/bcc/v1/system/ping to get alerted when:
    //   - Trust read service is on NullObject fallback (platform down)
    //   - Dispute adjudicator is unavailable
    //   - Score recalculation cron is >15 minutes overdue
    //   - Read model dirty queue has been stuck >10 minutes
    //
    // Cached for 30s to absorb probe storms.
    register_rest_route('bcc/v1', '/system/ping', [
        'methods'             => \WP_REST_Server::READABLE,
        'permission_callback' => '__return_true',
        'callback'            => function () {
            // Serve from cache if fresh — absorbs monitoring traffic.
            $cached = wp_cache_get('ping_result', 'bcc_health');
            if (is_array($cached)) {
                $resp = rest_ensure_response($cached['body']);
                $resp->set_status($cached['http']);
                $resp->header('Cache-Control', 'public, max-age=30');
                return $resp;
            }

            $checks = [];
            $healthy = true;

            // ── Check 1: Trust read service is real (not NullObject) ─
            $trustRead = \BCC\Core\ServiceLocator::hasRealService(
                \BCC\Core\Contracts\TrustReadServiceInterface::class
            );
            $checks['trust_read'] = $trustRead ? 'ok' : 'fail';
            if (!$trustRead) $healthy = false;

            // ── Check 2: Dispute adjudicator is real ──────────────────
            $disputeReady = \BCC\Core\ServiceLocator::hasRealService(
                \BCC\Core\Contracts\DisputeAdjudicationInterface::class
            );
            $checks['dispute_adjudicator'] = $disputeReady ? 'ok' : 'fail';
            if (!$disputeReady) $healthy = false;

            // ── Check 3: Score recalc cron freshness ─────────────────
            // Cron writes option 'bcc_trust_last_recalc_run' after each
            // successful run. If older than 15 min, cron has stalled.
            $lastRecalc = (int) get_option('bcc_trust_last_recalc_run', 0);
            $recalcAge  = time() - $lastRecalc;
            if ($lastRecalc === 0) {
                // Grace: system may have just installed — don't fail.
                $checks['score_recalc_cron'] = 'pending';
            } elseif ($recalcAge > 900) {
                $checks['score_recalc_cron'] = 'stale';
                $healthy = false;
            } else {
                $checks['score_recalc_cron'] = 'ok';
            }

            // ── Check 4: Read model dirty queue not stuck ────────────
            $rmDirty = wp_cache_get('bcc_rm_dirty_pages', 'bcc_rm_sync');
            if (is_array($rmDirty) && !empty($rmDirty)) {
                $oldest = min($rmDirty);
                $age    = time() - (int) $oldest;
                if ($age > 600) {
                    $checks['read_model_sync'] = 'stuck';
                    $healthy = false;
                } else {
                    $checks['read_model_sync'] = 'ok';
                }
            } else {
                $checks['read_model_sync'] = 'ok';
            }

            // ── Check 5: Object cache writable ────────────────────────
            $probeKey = 'bcc_ping_probe_' . wp_generate_password(6, false);
            wp_cache_set($probeKey, 1, 'bcc_health', 10);
            $writable = wp_cache_get($probeKey, 'bcc_health') === 1;
            wp_cache_delete($probeKey, 'bcc_health');
            $checks['cache_writable'] = $writable ? 'ok' : 'fail';
            if (!$writable) $healthy = false;

            // Public ping — return ONLY the overall status. The per-check
            // breakdown (cron staleness, adjudicator availability, circuit
            // state) is a reconnaissance signal for attackers timing abuse
            // windows. Full details remain available to authenticated
            // admins via the /system/health endpoint, which uses
            // current_user_can('manage_options') as its permission_callback.
            $body = [
                'status'    => $healthy ? 'ok' : 'degraded',
                'timestamp' => gmdate('c'),
            ];
            $http = $healthy ? 200 : 503;

            // Cache the trimmed public payload only. The detailed $checks
            // array was intentionally dropped — do not re-include it here.
            wp_cache_set('ping_result', ['body' => $body, 'http' => $http], 'bcc_health', 30);

            $resp = rest_ensure_response($body);
            $resp->set_status($http);
            $resp->header('Cache-Control', 'public, max-age=30');
            return $resp;
        },
    ]);
});

