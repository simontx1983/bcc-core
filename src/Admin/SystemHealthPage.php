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
            AdminMenu::SYSTEM_POSITION
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
        $tone      = $isOk ? 'ok' : 'bad';
        $statusUp  = strtoupper($status);

        $refreshUrl = esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG));

        printf(
            '<div class="bcc-status-banner bcc-status-banner--%1$s">'
            . '<strong style="font-size:18px;letter-spacing:1px;">%2$s</strong>'
            . '<span class="bcc-muted">timestamp: <code>%3$s</code></span>'
            . '<a href="%4$s" class="button" style="margin-left:auto;">Refresh</a>'
            . '</div>',
            esc_attr($tone),
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
            ? '<span style="color:var(--bcc-danger);font-weight:bold;">unknown</span>'
            : self::badgeNum((int) $pending, 100, 1000);

        $sourceBadge = match ($source) {
            'trust_engine' => AdminUi::badge($source, AdminUi::OK),
            'error'        => AdminUi::badge($source, AdminUi::BAD),
            default        => AdminUi::badge($source, AdminUi::WARN),
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
                ? AdminUi::badge('YES (external trigger expected)', AdminUi::OK)
                : AdminUi::badge('NO (internal cron active)', AdminUi::WARN),
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
            echo '<pre style="background:var(--bcc-bg-raised);padding:12px;max-height:280px;overflow:auto;border-left:4px solid var(--bcc-border-strong);">';
            echo esc_html((string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo '</pre>';
        }
    }

    /**
     * Project a DegradationMetrics::healthSnapshot() payload into flat
     * table rows. The snapshot shape is window-major
     * (`subsystems[<sub>]['current_hour'|'previous_hour'][<event>] => int`,
     * plus the `any_active` triage bit) — this helper is the ONLY place
     * the page interprets that contract, and it is pure so the shape
     * coupling is unit-testable. (An earlier revision of the renderer
     * read a nonexistent event-major shape, which blanked this panel
     * during real incidents.)
     *
     * @param array<string,mixed> $deg
     * @return list<array{subsystem: string, event: string, current: int, previous: int}>
     */
    public static function degradationRows(array $deg): array
    {
        $subsystems = $deg['subsystems'] ?? [];
        if (!is_array($subsystems)) {
            return [];
        }

        $rows = [];
        foreach ($subsystems as $subName => $subData) {
            if (!is_array($subData)) {
                continue;
            }
            $current  = is_array($subData['current_hour'] ?? null) ? $subData['current_hour'] : [];
            $previous = is_array($subData['previous_hour'] ?? null) ? $subData['previous_hour'] : [];

            // Union of event names across both windows, current-window
            // order first so the hot events lead.
            $events = array_keys($current + $previous);
            foreach ($events as $eventName) {
                $rows[] = [
                    'subsystem' => (string) $subName,
                    'event'     => (string) $eventName,
                    'current'   => (int) ($current[$eventName] ?? 0),
                    'previous'  => (int) ($previous[$eventName] ?? 0),
                ];
            }
        }
        return $rows;
    }

    /** @param array<string,mixed> $deg */
    private static function renderDegradationMetrics(array $deg): void
    {
        echo '<h3 style="margin-top:16px;">Degradation metrics (current hour + previous hour)</h3>';

        $anyActive = (bool) ($deg['any_active'] ?? false);
        if (!$anyActive) {
            echo '<p style="color:var(--bcc-success);">All subsystems quiet across both hourly buckets.</p>';
        }

        $rows = self::degradationRows($deg);
        if ($rows === []) {
            // healthSnapshot omits fully-quiet subsystems, so an empty
            // row set is the normal quiet state, not missing data.
            return;
        }

        echo '<table class="widefat striped" style="max-width:900px;">';
        echo '<thead><tr><th>Subsystem</th><th>Event</th><th style="text-align:right;">Current hour</th><th style="text-align:right;">Previous hour</th></tr></thead><tbody>';

        $bySub = [];
        foreach ($rows as $row) {
            $bySub[$row['subsystem']][] = $row;
        }
        foreach ($bySub as $subName => $subRows) {
            $isFirst = true;
            foreach ($subRows as $row) {
                $cur  = $row['current'];
                $prev = $row['previous'];
                $hot  = ($cur + $prev) > 0;

                $rowStyle = $hot ? 'background:var(--bcc-danger-bg);' : '';
                echo '<tr style="' . esc_attr($rowStyle) . '">';

                if ($isFirst) {
                    printf(
                        '<td rowspan="%d" style="font-weight:bold;vertical-align:top;">%s</td>',
                        count($subRows),
                        esc_html($subName)
                    );
                    $isFirst = false;
                }
                echo '<td><code>' . esc_html($row['event']) . '</code></td>';
                echo '<td style="text-align:right;' . ($cur > 0 ? 'color:var(--bcc-danger);font-weight:bold;' : 'color:var(--bcc-text-muted);') . '">' . esc_html((string) $cur) . '</td>';
                echo '<td style="text-align:right;' . ($prev > 0 ? 'color:var(--bcc-warning);font-weight:bold;' : 'color:var(--bcc-text-muted);') . '">' . esc_html((string) $prev) . '</td>';
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
                ? AdminUi::badge('not active', AdminUi::WARN)
                : AdminUi::badge((string) $v, AdminUi::INFO);
        }
        self::renderKeyValueTable('Build versions', $rows);
    }

    /** @param array<string,mixed> $data */
    private static function renderRawJson(array $data): void
    {
        $json = (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo '<details style="margin-top:32px;">';
        echo '<summary style="cursor:pointer;font-weight:bold;">Raw JSON</summary>';
        echo '<pre style="padding:14px;max-height:520px;overflow:auto;margin-top:8px;">';
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
        return AdminUi::badge(
            $ok ? $okLabel : $errLabel,
            $ok ? AdminUi::OK : AdminUi::BAD
        );
    }

    /** Numeric badge that turns yellow above $warnAt and red above $errAt. */
    private static function badgeNum(int $value, int $warnAt, int $errAt): string
    {
        $tone = AdminUi::OK;
        if ($value >= $errAt) {
            $tone = AdminUi::BAD;
        } elseif ($value >= $warnAt) {
            $tone = AdminUi::WARN;
        }
        return AdminUi::badge((string) $value, $tone);
    }

}
