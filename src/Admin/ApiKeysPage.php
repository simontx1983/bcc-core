<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * wp-admin renderer for BCC secret/API-key inventory.
 *
 * Operator OS v1 Phase 2 item #4. Closes the "is this secret
 * actually defined on this environment" diagnostic gap without
 * widening blast radius — read [memory:feedback-secrets-admin-
 * visibility]: raw values are NEVER editable or viewable in admin.
 *
 * v1 scope (this commit):
 *   - Per-key status: defined / missing.
 *   - Masked preview (first 4 + last 4 chars, middle masked) when
 *     the value is long enough to be safe to fingerprint.
 *   - Source plugin + severity + description.
 *   - Grouped by severity (critical first).
 *
 * Deferred to follow-ups:
 *   - Live health probes (cost API quota, slow the page, break
 *     when providers are down).
 *   - "Mark rotated at {ts}" action + rotation history (no
 *     rotation cadence yet — see plan §3 BUILD LATER).
 *
 * Filter contract: `apply_filters('bcc_api_keys_inventory', [])`.
 * Each plugin contributes its own constants. Mirrors the
 * bcc_system_health + bcc_expected_cron_hooks filter pattern.
 */
final class ApiKeysPage
{
    public const PAGE_SLUG = 'bcc-api-keys';

    private const SEVERITY_ORDER = ['critical' => 0, 'important' => 1, 'optional' => 2];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'API Keys',
            'API Keys',
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

        /**
         * @var array<string, array{label?:string,description?:string,source?:string,severity?:string}> $inventory
         */
        $inventory = apply_filters('bcc_api_keys_inventory', []);
        if (!is_array($inventory)) {
            $inventory = [];
        }

        // Sort by severity-then-name for stable rendering.
        uksort($inventory, function (string $a, string $b) use ($inventory): int {
            $sa = self::SEVERITY_ORDER[(string) ($inventory[$a]['severity'] ?? 'optional')] ?? 99;
            $sb = self::SEVERITY_ORDER[(string) ($inventory[$b]['severity'] ?? 'optional')] ?? 99;
            return $sa === $sb ? strcmp($a, $b) : $sa <=> $sb;
        });

        echo '<div class="wrap">';
        echo '<h1>BCC API Keys</h1>';
        self::renderSecurityNotice();
        self::renderSummary($inventory);
        self::renderInventoryTable($inventory);
        echo '</div>';
    }

    // ────────────────────────────────────────────────────────────
    // Section renderers
    // ────────────────────────────────────────────────────────────

    private static function renderSecurityNotice(): void
    {
        echo '<div class="notice notice-info inline" style="margin:8px 0;">';
        echo '<p><strong>Read-only.</strong> Values are stored in <code>wp-config.php</code> '
            . '(or environment variables / secret storage) and are never editable or viewable here. '
            . 'To rotate a secret: edit <code>wp-config.php</code> out of band, restart PHP, '
            . 'then this page reflects the new defined-state and masked preview.</p>';
        echo '</div>';
    }

    /** @param array<string,mixed> $inventory */
    private static function renderSummary(array $inventory): void
    {
        $totals = ['critical' => ['defined' => 0, 'missing' => 0],
                   'important' => ['defined' => 0, 'missing' => 0],
                   'optional' => ['defined' => 0, 'missing' => 0]];

        foreach ($inventory as $constant => $meta) {
            $sev = (string) ($meta['severity'] ?? 'optional');
            if (!isset($totals[$sev])) {
                $totals[$sev] = ['defined' => 0, 'missing' => 0];
            }
            $defined = defined((string) $constant)
                && (string) constant((string) $constant) !== '';
            $totals[$sev][$defined ? 'defined' : 'missing']++;
        }

        $criticalMissing = $totals['critical']['missing'];

        if ($criticalMissing > 0) {
            echo '<div class="notice notice-error inline" style="margin:8px 0;">';
            printf(
                '<p><strong>%d critical secret(s) missing.</strong> See the table below. '
                . 'Affected subsystems will be inert until each is set in <code>wp-config.php</code>.</p>',
                (int) $criticalMissing
            );
            echo '</div>';
        }

        echo '<h2 style="margin-top:24px;">Summary</h2>';
        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';
        foreach (['critical', 'important', 'optional'] as $sev) {
            $row = $totals[$sev] ?? ['defined' => 0, 'missing' => 0];
            $defCount = $row['defined'];
            $missCount = $row['missing'];

            $missingHtml = $missCount === 0
                ? self::badge('0', '#46b450')
                : self::badge((string) $missCount, $sev === 'critical' ? '#dc3232' : '#dba617');

            printf(
                '<tr><th style="width:240px;text-transform:uppercase;letter-spacing:1px;">%s</th><td>%s defined &nbsp; • &nbsp; %s missing</td></tr>',
                esc_html($sev),
                esc_html((string) $defCount),
                $missingHtml
            );
        }
        echo '</tbody></table>';
    }

    /** @param array<string,mixed> $inventory */
    private static function renderInventoryTable(array $inventory): void
    {
        echo '<h2 style="margin-top:24px;">Inventory ('
            . esc_html((string) count($inventory)) . ')</h2>';

        if ($inventory === []) {
            echo '<p>(no contributors registered for <code>bcc_api_keys_inventory</code> filter)</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1200px;">';
        echo '<thead><tr>';
        echo '<th>Constant</th>';
        echo '<th>Severity</th>';
        echo '<th>Source</th>';
        echo '<th>Status</th>';
        echo '<th>Masked preview</th>';
        echo '<th>Description</th>';
        echo '</tr></thead><tbody>';

        foreach ($inventory as $constant => $meta) {
            $constantStr = (string) $constant;
            $severity    = (string) ($meta['severity']    ?? 'optional');
            $source      = (string) ($meta['source']      ?? '');
            $description = (string) ($meta['description'] ?? '');

            $defined  = defined($constantStr);
            $rawValue = $defined ? (string) constant($constantStr) : '';
            $present  = $defined && $rawValue !== '';

            $rowStyle = '';
            if (!$present && $severity === 'critical') {
                $rowStyle = 'background:#fcf0f1;';
            } elseif (!$present && $severity === 'important') {
                $rowStyle = 'background:#fffaeb;';
            }

            echo '<tr style="' . esc_attr($rowStyle) . '">';
            echo '<td><code>' . esc_html($constantStr) . '</code></td>';
            echo '<td>' . self::severityBadge($severity) . '</td>';
            echo '<td>' . esc_html($source) . '</td>';
            echo '<td>' . self::statusBadge($present, $severity) . '</td>';
            echo '<td>' . ($present
                ? '<code style="font-size:12px;">' . esc_html(self::mask($rawValue)) . '</code>'
                : '<span style="color:#888;">—</span>')
                . '</td>';
            echo '<td style="font-size:13px;color:#444;">' . esc_html($description) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Constant-width masked preview. Trades a 4-char fingerprint
     * (enough for an operator to confirm "yes this is the right
     * value I expected" against an out-of-band reference like a
     * password manager) for never exposing the secret in full to
     * an admin session.
     *
     * Values <= 12 chars are too short to fingerprint safely (the
     * first/last 4 + middle would reveal too much of the body),
     * so we show length only.
     */
    private static function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 12) {
            return '[' . $len . ' chars]';
        }
        $first  = substr($value, 0, 4);
        $last   = substr($value, -4);
        $middle = str_repeat('•', max(4, min(20, $len - 8)));
        return $first . $middle . $last;
    }

    private static function severityBadge(string $severity): string
    {
        return match ($severity) {
            'critical'  => self::badge('CRITICAL',  '#dc3232'),
            'important' => self::badge('important', '#dba617'),
            'optional'  => self::badge('optional',  '#888'),
            default     => self::badge($severity,   '#888'),
        };
    }

    private static function statusBadge(bool $present, string $severity): string
    {
        if ($present) {
            return self::badge('defined', '#46b450');
        }
        return $severity === 'critical'
            ? self::badge('MISSING', '#dc3232')
            : self::badge('not set', '#dba617');
    }

    private static function badge(string $text, string $color): string
    {
        return sprintf(
            '<span style="display:inline-block;padding:2px 10px;background:%1$s;color:#fff;border-radius:3px;font-weight:bold;font-size:12px;letter-spacing:0.5px;">%2$s</span>',
            esc_attr($color),
            esc_html($text)
        );
    }
}
