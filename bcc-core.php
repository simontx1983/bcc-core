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

// ── Persistent object cache warning ─────────────────────────────
// Rate limiting and caching degrade significantly without Redis/Memcached.
// Show a non-dismissible admin warning on production sites.

add_action('admin_notices', function () {
    if (wp_using_ext_object_cache()) {
        return;
    }
    // Only warn on production-like environments (not local dev).
    if (defined('WP_DEBUG') && WP_DEBUG) {
        return;
    }
    echo '<div class="notice notice-warning"><p>';
    echo '<strong>Blue Collar Crypto:</strong> No persistent object cache detected. ';
    echo 'Rate limiting and search caching are operating in degraded mode (per-request only). ';
    echo 'Install Redis or Memcached for production use.';
    echo '</p></div>';
});

// ── ServiceLocator freeze ───────────────────────────────────────
// After all plugins have loaded, freeze the ServiceLocator cache so
// late-registered filters cannot replace already-resolved services.

add_action('plugins_loaded', [\BCC\Core\ServiceLocator::class, 'freeze'], PHP_INT_MAX);

// ── Rate-limit row cleanup ──────────────────────────────────────
// Throttle's DB fallback and RateLimiter write rows to wp_options
// that never auto-expire. This hourly cron garbage-collects expired
// entries in bounded batches to avoid table-locking.

add_action('bcc_core_rl_cleanup', function () {
    global $wpdb;

    // Clean both prefixes: _bcc_rl_ (Throttle) and _transient_bcc_rl_ (RateLimiter).
    // Use LIMIT to prevent table-lock on large wp_options tables.
    // Loop up to 10 times (10000 rows max per cron tick).
    $patterns = ["'\\_bcc\\_rl\\_%'", "'\\_transient\\_bcc\\_rl\\_%'"];

    foreach ($patterns as $pattern) {
        $deleted = 0;
        $iterations = 0;
        do {
            $deleted = (int) $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE {$pattern}
                   AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < UNIX_TIMESTAMP()
                 LIMIT 1000"
            );
            $iterations++;
        } while ($deleted >= 1000 && $iterations < 10);
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
    // Reschedule if still on old hourly cadence.
    $existing = wp_get_schedule('bcc_core_rl_cleanup');
    if ($existing === 'hourly') {
        wp_clear_scheduled_hook('bcc_core_rl_cleanup');
    }
    if (!wp_next_scheduled('bcc_core_rl_cleanup')) {
        wp_schedule_event(time(), 'bcc_thirty_minutes', 'bcc_core_rl_cleanup');
    }
    // Remove the old daily event if it exists.
    if (wp_next_scheduled('bcc_core_daily_cleanup')) {
        wp_clear_scheduled_hook('bcc_core_daily_cleanup');
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
            global $wpdb;
            $rlRowCount = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_bcc\\_rl\\_%' OR option_name LIKE '\\_transient\\_bcc\\_rl\\_%'"
            );

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
            $threadsRow       = $wpdb->get_row("SHOW GLOBAL STATUS LIKE 'Threads_connected'");
            $dbThreadsConnected = $threadsRow ? (int) $threadsRow->Value : 0;
            $runningRow       = $wpdb->get_row("SHOW GLOBAL STATUS LIKE 'Threads_running'");
            $dbThreadsRunning   = $runningRow ? (int) $runningRow->Value : 0;
            $dbMaxConnections   = (int) $wpdb->get_var("SELECT @@max_connections");

            // ── Recalculation queue depth ────────────────────────────────
            $recalcPending = 0;
            if (class_exists('\\BCC\\Trust\\Database\\TableRegistry')) {
                $scoresTable   = \BCC\Trust\Database\TableRegistry::scores();
                $recalcPending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$scoresTable} WHERE recalculate_required = 1");
            }

            // ── Plugin-specific health (pulled via filter) ──────────────
            $pluginHealth = apply_filters('bcc_system_health', []);

            return rest_ensure_response([
                'status'    => 'ok',
                'timestamp' => gmdate('c'),
                'cache'     => [
                    'persistent_object_cache' => $hasRedis,
                    'cache_writable'          => $cacheWritable,
                    'rate_limit_rows'         => $rlRowCount,
                ],
                'read_model' => [
                    'dirty_pages'      => $rmDirtyCount,
                    'max_age_seconds'  => $rmMaxAgeSec,
                ],
                'recalculation' => [
                    'pending_pages' => $recalcPending,
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
                'wp_cron'   => [
                    'disabled'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                ],
                'plugins'   => $pluginHealth,
            ]);
        },
    ]);
});

