<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * wp-admin renderer for BCC cron-system state.
 *
 * Operator OS v1 Phase 2 item #3. Closes the diagnostic gap for the
 * V2-NFT cron-drift incident class: hooks registered activation-only
 * silently disappear on sites updated without reactivation, and the
 * existing self-heal on plugins_loaded leaves no admin-visible trace
 * of "this hook is supposed to be scheduled but isn't."
 *
 * Data sources:
 *   - apply_filters('bcc_expected_cron_hooks', [])  — canonical list,
 *     each plugin contributes its own. Mirrors the existing
 *     bcc_system_health filter pattern (per-plugin contributors,
 *     core renders).
 *   - _get_cron_array()                             — actual scheduled
 *     events (WP internal table).
 *   - wp_get_schedules()                            — registered
 *     intervals (built-ins + custom bcc_* ones).
 *   - wp_next_scheduled($hook)                      — per-canonical-hook
 *     drift probe.
 *
 * Render-only. Manual-trigger / clear-hook actions live in Phase 3
 * "Maintenance Cron" — out of scope here.
 */
final class CronPage
{
    public const PAGE_SLUG = 'bcc-cron';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'Cron',
            'Cron',
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

        echo '<div class="wrap">';
        echo '<h1>BCC Cron</h1>';

        self::renderEnvironment();
        self::renderCanonicalHooks();
        self::renderScheduledEvents();
        self::renderRegisteredIntervals();

        echo '</div>';
    }

    // ────────────────────────────────────────────────────────────
    // Section renderers
    // ────────────────────────────────────────────────────────────

    private static function renderEnvironment(): void
    {
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $now      = time();

        echo '<h2 style="margin-top:24px;">Environment</h2>';
        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';

        echo '<tr><th style="width:240px;">wp-cron disabled</th><td>'
            . ($disabled
                ? self::badge('YES (external trigger expected)', '#46b450')
                : self::badge('NO (internal cron active)', '#dba617'))
            . '</td></tr>';

        echo '<tr><th>Current UTC</th><td><code>'
            . esc_html(gmdate('Y-m-d H:i:s', $now))
            . '</code></td></tr>';

        echo '</tbody></table>';
    }

    private static function renderCanonicalHooks(): void
    {
        /**
         * @var array<string, array{interval?:string,source?:string,description?:string}> $expected
         */
        $expected = apply_filters('bcc_expected_cron_hooks', []);
        if (!is_array($expected)) {
            $expected = [];
        }
        ksort($expected);

        echo '<h2 style="margin-top:24px;">Canonical hooks ('
            . esc_html((string) count($expected)) . ')</h2>';
        echo '<p style="color:#666;">Expected by the bcc-* plugins. A "missing" row = drift '
            . '(activation-only hook silently not registered on this site). '
            . 'The plugins_loaded self-heal re-registers on next page load; sustained '
            . 'missing rows indicate the self-heal itself is broken.</p>';

        if ($expected === []) {
            echo '<p>(no contributors registered for <code>bcc_expected_cron_hooks</code> filter)</p>';
            return;
        }

        $driftCount = 0;
        foreach (array_keys($expected) as $hook) {
            if (wp_next_scheduled((string) $hook) === false) {
                $driftCount++;
            }
        }

        if ($driftCount > 0) {
            echo '<div class="notice notice-error inline" style="margin:8px 0;"><p><strong>Drift detected:</strong> '
                . esc_html((string) $driftCount)
                . ' canonical hook(s) not currently scheduled. See the table below for which.</p></div>';
        }

        echo '<table class="widefat striped" style="max-width:1100px;">';
        echo '<thead><tr>';
        echo '<th>Hook</th>';
        echo '<th>Expected interval</th>';
        echo '<th>Source</th>';
        echo '<th>Scheduled?</th>';
        echo '<th>Next run</th>';
        echo '</tr></thead><tbody>';

        foreach ($expected as $hook => $meta) {
            $hookStr  = (string) $hook;
            $interval = (string) ($meta['interval'] ?? '');
            $source   = (string) ($meta['source']   ?? '');
            $nextTs   = wp_next_scheduled($hookStr);

            $rowStyle = $nextTs === false ? 'background:#fcf0f1;' : '';
            echo '<tr style="' . esc_attr($rowStyle) . '">';
            echo '<td><code>' . esc_html($hookStr) . '</code></td>';
            echo '<td><code>' . esc_html($interval) . '</code></td>';
            echo '<td>' . esc_html($source) . '</td>';
            echo '<td>' . ($nextTs === false
                ? self::badge('MISSING', '#dc3232')
                : self::badge('✓', '#46b450'))
                . '</td>';
            echo '<td>' . ($nextTs === false
                ? '<span style="color:#888;">—</span>'
                : self::formatNextRun((int) $nextTs))
                . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function renderScheduledEvents(): void
    {
        $cronArray = _get_cron_array();
        if (!is_array($cronArray)) {
            $cronArray = [];
        }

        // Flatten the [$timestamp => [$hook => [...args]]] map.
        $rows = [];
        foreach ($cronArray as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hookName => $events) {
                if (!is_array($events)) {
                    continue;
                }
                foreach ($events as $event) {
                    $rows[] = [
                        'timestamp' => (int) $timestamp,
                        'hook'      => (string) $hookName,
                        'schedule'  => is_array($event) && isset($event['schedule'])
                            ? (string) $event['schedule']
                            : '',
                        'args'      => is_array($event) && isset($event['args']) && is_array($event['args'])
                            ? $event['args']
                            : [],
                    ];
                }
            }
        }
        usort($rows, fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        echo '<h2 style="margin-top:32px;">Currently scheduled (' . esc_html((string) count($rows)) . ')</h2>';
        echo '<p style="color:#666;">Live read of <code>_get_cron_array()</code>. Includes dynamic hooks '
            . '(per-chain <code>bcc_chain_refresh_*</code>, single-event async jobs) that the canonical '
            . 'list above doesn\'t cover.</p>';

        if ($rows === []) {
            echo '<p>(no scheduled events)</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1100px;">';
        echo '<thead><tr>';
        echo '<th>Next run</th>';
        echo '<th>Hook</th>';
        echo '<th>Schedule</th>';
        echo '<th>Args</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $isBcc      = strpos($r['hook'], 'bcc_') === 0
                || strpos($r['hook'], 'BCC\\') !== false;
            $hookCol    = '<code>' . esc_html($r['hook']) . '</code>';
            $scheduleHtml = $r['schedule'] !== ''
                ? '<code>' . esc_html($r['schedule']) . '</code>'
                : '<span style="color:#888;">single-event</span>';

            $argsHtml = $r['args'] === []
                ? '<span style="color:#888;">—</span>'
                : '<code style="font-size:11px;">' . esc_html(self::shortArgs($r['args'])) . '</code>';

            $rowStyle = $isBcc ? '' : 'color:#888;';
            echo '<tr style="' . esc_attr($rowStyle) . '">';
            echo '<td>' . self::formatNextRun($r['timestamp']) . '</td>';
            echo '<td>' . $hookCol . '</td>';
            echo '<td>' . $scheduleHtml . '</td>';
            echo '<td>' . $argsHtml . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function renderRegisteredIntervals(): void
    {
        $schedules = wp_get_schedules();
        if (!is_array($schedules) || $schedules === []) {
            return;
        }
        ksort($schedules);

        echo '<h2 style="margin-top:32px;">Registered intervals</h2>';
        echo '<table class="widefat striped" style="max-width:760px;">';
        echo '<thead><tr><th>Slug</th><th>Seconds</th><th>Display</th></tr></thead><tbody>';

        foreach ($schedules as $slug => $info) {
            // wp_get_schedules() is typed array<string, array{interval:int,
            // display:string}>, so the first isset narrows $info; a second
            // is_array() would be redundant (PHPStan function.alreadyNarrowedType).
            // isset() still guards a malformed cron_schedules filter entry.
            $seconds = isset($info['interval']) ? (int) $info['interval']  : 0;
            $display = isset($info['display'])  ? (string) $info['display'] : '';
            $isBcc   = strpos((string) $slug, 'bcc_') === 0;

            $rowStyle = $isBcc ? '' : 'color:#888;';
            echo '<tr style="' . esc_attr($rowStyle) . '">';
            echo '<td><code>' . esc_html((string) $slug) . '</code></td>';
            echo '<td>' . esc_html(number_format($seconds)) . '</td>';
            echo '<td>' . esc_html($display) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    private static function formatNextRun(int $ts): string
    {
        $now   = time();
        $delta = $ts - $now;

        $color = '#46b450';
        if ($delta < -300) {
            $color = '#dc3232';
        } elseif ($delta < 0) {
            $color = '#dba617';
        }

        $rel = $delta >= 0
            ? 'in ' . self::humanInterval($delta)
            : self::humanInterval(-$delta) . ' ago';

        return sprintf(
            '<code style="font-size:12px;">%s</code> %s',
            esc_html(gmdate('Y-m-d H:i:s', $ts)),
            self::badge($rel, $color)
        );
    }

    private static function humanInterval(int $sec): string
    {
        if ($sec < 60)    { return $sec . 's'; }
        if ($sec < 3600)  { return floor($sec / 60) . 'm'; }
        if ($sec < 86400) { return floor($sec / 3600) . 'h'; }
        return floor($sec / 86400) . 'd';
    }

    /**
     * @param array<mixed> $args
     */
    private static function shortArgs(array $args): string
    {
        $json = (string) wp_json_encode($args, JSON_UNESCAPED_SLASHES);
        return strlen($json) > 60 ? substr($json, 0, 57) . '...' : $json;
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
