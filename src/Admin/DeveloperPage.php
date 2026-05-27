<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * wp-admin renderer for engineer-grade BCC internals.
 *
 * Operator OS v1 Phase 3 item #2. Saves keystrokes on diagnostics
 * that Phillip + Tialuxe currently do via SSH + WP CLI + mysql MCP.
 *
 * Architecture: filter-based panel registry — each plugin contributes
 * its own panels via apply_filters('bcc_developer_panels', []) and
 * registers any admin-post handlers it needs in its own boot file.
 * bcc-core just renders the page shell.
 *
 * v1 scope (this commit):
 *   - Read Model panel (bcc-trust) — drift, gaps, dirty queue lag.
 *   - Search Index panel (bcc-search) — install state + manual
 *     rebuild action.
 *
 * Deferred (intentionally not in v1):
 *   - Raw Data Inspection — duplicates Trust Engine Debug page.
 *   - Schema / Version tracking — thin without a real migration
 *     system; the existing BCC_*_VERSION constants + System >
 *     Health "Build versions" block already cover the operator
 *     question of "what version is live?"
 *
 * @phpstan-type PanelEntry array{
 *     title: string,
 *     sort?: int,
 *     render: callable(): void,
 * }
 */
final class DeveloperPage
{
    public const PAGE_SLUG = 'bcc-developer';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'Developer',
            'Developer',
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
         * @var array<string, array{title:string,sort?:int,render:callable}> $panels
         */
        $panels = apply_filters('bcc_developer_panels', []);
        if (!is_array($panels)) {
            $panels = [];
        }

        // Sort by `sort` then by panel key for stable ordering.
        uksort($panels, function (string $a, string $b) use ($panels): int {
            $sa = (int) ($panels[$a]['sort'] ?? 100);
            $sb = (int) ($panels[$b]['sort'] ?? 100);
            return $sa === $sb ? strcmp($a, $b) : $sa <=> $sb;
        });

        echo '<div class="wrap">';
        echo '<h1>BCC Developer</h1>';
        echo '<p style="color:#666;">Engineer-grade BCC internals. Saves SSH / WP CLI / mysql round-trips for routine diagnostics.</p>';

        if (isset($_GET['rebuilt'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Search FT index rebuild triggered.</p></div>';
        }
        if (isset($_GET['rebuild_failed'])) {
            $err = sanitize_text_field((string) $_GET['rebuild_failed']);
            printf(
                '<div class="notice notice-error is-dismissible"><p>FT index rebuild failed: %s</p></div>',
                esc_html($err)
            );
        }

        if ($panels === []) {
            echo '<p>(no contributors registered for <code>bcc_developer_panels</code> filter)</p>';
            echo '</div>';
            return;
        }

        foreach ($panels as $panelId => $panel) {
            $title  = (string) ($panel['title'] ?? (string) $panelId);
            $render = $panel['render'] ?? null;
            if (!is_callable($render)) {
                continue;
            }

            printf(
                '<h2 style="margin-top:32px;border-top:1px solid #c3c4c7;padding-top:24px;">%s</h2>',
                esc_html($title)
            );

            try {
                $render();
            } catch (\Throwable $e) {
                printf(
                    '<div class="notice notice-error inline"><p>Panel <code>%s</code> threw: %s</p></div>',
                    esc_html((string) $panelId),
                    esc_html($e->getMessage())
                );
            }
        }

        echo '</div>';
    }
}
