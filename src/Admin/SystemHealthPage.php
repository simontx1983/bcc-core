<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * wp-admin renderer for the /bcc/v1/system/health endpoint.
 *
 * Operator OS v1 Phase 2 item #1 — closes the "operator must curl JSON"
 * friction for the existing system-health endpoint. Pure render of the
 * REST response; no new domain logic, no new storage.
 *
 * Architecture: invokes the registered REST callback internally via
 * rest_do_request() so all filter contributions (`bcc_system_health`)
 * and the existing manage_options permission check participate
 * naturally. Refresh = page reload. No AJAX.
 *
 * Section layout matches the endpoint's top-level keys (status,
 * cache, read_model, recalculation, database, services,
 * trust_subsystem, wp_cron, plugins). The `plugins.degradation_metrics`
 * subtree (18 subsystems x N events) gets a dedicated section because
 * it is the single most operationally important read on the page.
 */
final class SystemHealthPage
{
    public const PAGE_SLUG = 'bcc-system-health';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
    }

    public static function registerMenu(): void
    {
        add_menu_page(
            'BCC System',
            'BCC System',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render'],
            'dashicons-performance',
            25
        );

        // Explicit submenu so the menu title doesn't double up.
        add_submenu_page(
            self::PAGE_SLUG,
            'System Health',
            'Health',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }

        $data = self::fetchHealth();

        echo '<div class="wrap">';
        echo '<h1>BCC System Health</h1>';

        if (isset($data['error']) && $data['error']) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Could not load /bcc/v1/system/health:'),
                esc_html((string) ($data['message'] ?? 'unknown error'))
            );
            echo '</div>';
            return;
        }

        self::renderHeader($data);
        self::renderCache($data['cache'] ?? []);
        self::renderReadModel($data['read_model'] ?? []);
        self::renderRecalculation($data['recalculation'] ?? []);
        self::renderDatabase($data['database'] ?? []);
        self::renderServices($data['services'] ?? []);
        self::renderTrustSubsystem($data['trust_subsystem'] ?? []);
        self::renderWpCron($data['wp_cron'] ?? []);
        self::renderPluginHealth($data['plugins'] ?? []);
        self::renderVersions();
        self::renderRawJson($data);

        echo '</div>';
    }

    /** @return array<string, mixed> */
    private static function fetchHealth(): array
    {
        $request  = new \WP_REST_Request('GET', '/bcc/v1/system/health');
        $response = rest_do_request($request);

        if (is_wp_error($response)) {
            return ['error' => true, 'message' => $response->get_error_message()];
        }

        if ($response->is_error()) {
            $body = $response->get_data();
            $msg  = is_array($body) && isset($body['message']) ? (string) $body['message'] : 'HTTP ' . $response->get_status();
            return ['error' => true, 'message' => $msg];
        }

        $data = $response->get_data();
        return is_array($data) ? $data : ['error' => true, 'message' => 'unexpected response shape'];
    }

    // ────────────────────────────────────────────────────────────
    // Section renderers
    // ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private static function renderHeader(array $data): void
    {
        $status = (string) ($data['status'] ?? 'unknown');
        $ts     = (string) ($data['timestamp'] ?? '');

        $isOk      = $status === 'ok';
        $bg        = $isOk ? '#46b450' : '#dc3232';
        $statusUp  = strtoupper($status);

        $refreshUrl = esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG));

        printf(
            '<div style="display:flex;align-items:center;gap:16px;margin:16px 0;padding:14px 20px;background:%1$s;color:#fff;border-radius:4px;">'
            . '<strong style="font-size:18px;letter-spacing:1px;">%2$s</strong>'
            . '<span style="opacity:0.85;">timestamp: <code style="background:rgba(255,255,255,0.18);color:#fff;padding:2px 6px;border-radius:3px;">%3$s</code></span>'
            . '<a href="%4$s" class="button" style="margin-left:auto;">Refresh</a>'
            . '</div>',
            esc_attr($bg),
            esc_html($statusUp),
            esc_html($ts),
            $refreshUrl
        );
    }

    /** @param array<string,mixed> $cache */
    private static function renderCache(array $cache): void
    {
        $rows = [
            'Persistent object cache' => self::badgeBool(
                (bool) ($cache['persistent_object_cache'] ?? false),
                'Redis / Memcached active',
                'Not active'
            ),
            'Cache writable'          => self::badgeBool((bool) ($cache['cache_writable'] ?? false)),
            'Rate-limiter degraded'   => self::badgeBool(
                !(bool) ($cache['rate_limiter_degraded'] ?? false),
                'OK',
                'DEGRADED — fail-closed'
            ),
            'Rate-limit rows'         => esc_html((string) (int) ($cache['rate_limit_rows'] ?? 0)),
        ];
        self::renderKeyValueTable('Cache', $rows);
    }

    /** @param array<string,mixed> $rm */
    private static function renderReadModel(array $rm): void
    {
        $dirty = (int) ($rm['dirty_pages'] ?? 0);
        $ageSec = (int) ($rm['max_age_seconds'] ?? 0);

        $rows = [
            'Dirty pages'    => self::badgeNum($dirty, 50, 500),
            'Oldest age (s)' => self::badgeNum($ageSec, 600, 1800),
        ];
        self::renderKeyValueTable('Read model', $rows);
    }

    /** @param array<string,mixed> $rc */
    private static function renderRecalculation(array $rc): void
    {
        $pending = $rc['pending_pages'] ?? null;
        $source  = (string) ($rc['source'] ?? 'unavailable');

        $pendingDisplay = $pending === null
            ? '<span style="color:#dc3232;font-weight:bold;">unknown</span>'
            : self::badgeNum((int) $pending, 100, 1000);

        $sourceBadge = match ($source) {
            'trust_engine' => self::badgeText($source, '#46b450'),
            'error'        => self::badgeText($source, '#dc3232'),
            default        => self::badgeText($source, '#dba617'),
        };

        $rows = [
            'Pending pages' => $pendingDisplay,
            'Source'        => $sourceBadge,
        ];
        self::renderKeyValueTable('Recalculation queue', $rows);
    }

    /** @param array<string,mixed> $db */
    private static function renderDatabase(array $db): void
    {
        $threadsConn = (int) ($db['threads_connected'] ?? 0);
        $threadsRun  = (int) ($db['threads_running'] ?? 0);
        $maxConn     = (int) ($db['max_connections'] ?? 0);
        $util        = (float) ($db['utilization_pct'] ?? 0);

        $utilBadge = self::badgeNum((int) round($util), 50, 80) . ' %';

        $rows = [
            'Threads connected' => esc_html((string) $threadsConn),
            'Threads running'   => esc_html((string) $threadsRun),
            'Max connections'   => esc_html((string) $maxConn),
            'Utilization'       => $utilBadge,
        ];
        self::renderKeyValueTable('Database', $rows);
    }

    /** @param array<string,bool> $services */
    private static function renderServices(array $services): void
    {
        $rows = [];
        foreach ($services as $name => $real) {
            $rows[(string) $name] = self::badgeBool((bool) $real, 'Real', 'NullObject');
        }
        self::renderKeyValueTable('Services', $rows);
    }

    /** @param array<string,bool> $sub */
    private static function renderTrustSubsystem(array $sub): void
    {
        $rows = [];
        foreach ($sub as $name => $ok) {
            $rows[str_replace('_', ' ', (string) $name)] = self::badgeBool((bool) $ok);
        }
        self::renderKeyValueTable('Trust subsystem', $rows);
    }

    /** @param array<string,mixed> $cron */
    private static function renderWpCron(array $cron): void
    {
        // DISABLE_WP_CRON being true is the EXPECTED prod posture (external
        // trigger drives wp-cron), so we invert the badge polarity.
        $disabled = (bool) ($cron['disabled'] ?? false);

        $rows = [
            'wp-cron disabled' => $disabled
                ? self::badgeText('YES (external trigger expected)', '#46b450')
                : self::badgeText('NO (internal cron active)', '#dba617'),
        ];
        self::renderKeyValueTable('wp-cron', $rows);
    }

    /** @param array<string,mixed> $plugins */
    private static function renderPluginHealth(array $plugins): void
    {
        if ($plugins === []) {
            return;
        }

        echo '<h2 style="margin-top:32px;">Plugin contributions</h2>';

        // Render degradation_metrics specially — it is the single most
        // operationally relevant subtree on the page.
        $deg = $plugins['degradation_metrics'] ?? null;
        if (is_array($deg)) {
            self::renderDegradationMetrics($deg);
            unset($plugins['degradation_metrics']);
        }

        // Everything else: pretty-print per-key. Shape is arbitrary per
        // contributor (NftIndexerHealthSnapshot, bcc-search, etc.).
        foreach ($plugins as $key => $value) {
            printf('<h3 style="margin-top:24px;">%s</h3>', esc_html((string) $key));
            echo '<pre style="background:#f6f7f7;padding:12px;max-height:280px;overflow:auto;border-left:4px solid #c3c4c7;">';
            echo esc_html((string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo '</pre>';
        }
    }

    /** @param array<string,mixed> $deg */
    private static function renderDegradationMetrics(array $deg): void
    {
        echo '<h3 style="margin-top:16px;">Degradation metrics (current hour + previous hour)</h3>';

        $anyNonzero = (bool) ($deg['any_nonzero'] ?? false);
        if (!$anyNonzero) {
            echo '<p style="color:#46b450;">All 18 subsystems quiet across both hourly buckets.</p>';
        }

        $subsystems = $deg['subsystems'] ?? [];
        if (!is_array($subsystems) || $subsystems === []) {
            echo '<p>(no subsystem data)</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:900px;">';
        echo '<thead><tr><th>Subsystem</th><th>Event</th><th style="text-align:right;">Current hour</th><th style="text-align:right;">Previous hour</th></tr></thead><tbody>';

        foreach ($subsystems as $subName => $subData) {
            if (!is_array($subData)) {
                continue;
            }
            $events = $subData['events'] ?? [];
            if (!is_array($events) || $events === []) {
                continue;
            }
            $isFirst = true;
            $eventCount = count($events);
            foreach ($events as $eventName => $counts) {
                $cur  = is_array($counts) ? (int) ($counts['current']  ?? 0) : 0;
                $prev = is_array($counts) ? (int) ($counts['previous'] ?? 0) : 0;
                $hot  = ($cur + $prev) > 0;

                $rowStyle = $hot ? 'background:#fcf0f1;' : '';
                echo '<tr style="' . esc_attr($rowStyle) . '">';

                if ($isFirst) {
                    printf(
                        '<td rowspan="%d" style="font-weight:bold;vertical-align:top;">%s</td>',
                        $eventCount,
                        esc_html((string) $subName)
                    );
                    $isFirst = false;
                }
                echo '<td><code>' . esc_html((string) $eventName) . '</code></td>';
                echo '<td style="text-align:right;' . ($cur > 0 ? 'color:#dc3232;font-weight:bold;' : 'color:#888;') . '">' . esc_html((string) $cur) . '</td>';
                echo '<td style="text-align:right;' . ($prev > 0 ? 'color:#dba617;font-weight:bold;' : 'color:#888;') . '">' . esc_html((string) $prev) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    /**
     * Build versions block. Lets an operator confirm at a glance which
     * version of each BCC plugin is live after a Git Updater pull.
     */
    private static function renderVersions(): void
    {
        $versions = [
            'bcc-core'   => defined('BCC_CORE_VERSION')   ? BCC_CORE_VERSION   : null,
            'bcc-trust'  => defined('BCC_TRUST_VERSION')  ? BCC_TRUST_VERSION  : null,
            'bcc-search' => defined('BCC_SEARCH_VERSION') ? BCC_SEARCH_VERSION : null,
        ];

        $rows = [];
        foreach ($versions as $plugin => $v) {
            $rows[(string) $plugin] = $v === null
                ? self::badgeText('not active', '#dba617')
                : self::badgeText((string) $v, '#2271b1');
        }
        self::renderKeyValueTable('Build versions', $rows);
    }

    /** @param array<string,mixed> $data */
    private static function renderRawJson(array $data): void
    {
        $json = (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo '<details style="margin-top:32px;">';
        echo '<summary style="cursor:pointer;font-weight:bold;">Raw JSON</summary>';
        echo '<pre style="background:#1d2327;color:#c5d3df;padding:14px;max-height:520px;overflow:auto;border-radius:4px;margin-top:8px;">';
        echo esc_html($json);
        echo '</pre>';
        echo '</details>';
    }

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    /** @param array<string,string> $rows */
    private static function renderKeyValueTable(string $title, array $rows): void
    {
        echo '<h2 style="margin-top:24px;">' . esc_html($title) . '</h2>';
        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';
        foreach ($rows as $label => $valueHtml) {
            echo '<tr>';
            echo '<th style="width:240px;">' . esc_html((string) $label) . '</th>';
            echo '<td>' . $valueHtml . '</td>'; // value is pre-escaped by the badge helpers
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function badgeBool(bool $ok, string $okLabel = 'OK', string $errLabel = 'FAIL'): string
    {
        return self::badgeText(
            $ok ? $okLabel : $errLabel,
            $ok ? '#46b450' : '#dc3232'
        );
    }

    /** Numeric badge that turns yellow above $warnAt and red above $errAt. */
    private static function badgeNum(int $value, int $warnAt, int $errAt): string
    {
        $color = '#46b450';
        if ($value >= $errAt) {
            $color = '#dc3232';
        } elseif ($value >= $warnAt) {
            $color = '#dba617';
        }
        return self::badgeText((string) $value, $color);
    }

    private static function badgeText(string $text, string $color): string
    {
        return sprintf(
            '<span style="display:inline-block;padding:2px 10px;background:%1$s;color:#fff;border-radius:3px;font-weight:bold;font-size:12px;letter-spacing:0.5px;">%2$s</span>',
            esc_attr($color),
            esc_html($text)
        );
    }
}
