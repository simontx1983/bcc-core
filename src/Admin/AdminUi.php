<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * Shared render helpers for every BCC wp-admin page.
 *
 * Consolidation point for what used to be byte-identical private copies
 * in SystemHealthPage / ApiKeysPage / CronPage (bcc-core), WebhooksPage /
 * HolderGroupsPage (bcc-trust) and the bcc-search developer panels. The
 * markup carries CLASSES, not colours — the palette lives once in
 * assets/css/bcc-admin.css (enqueued by {@see AdminTheme}), so every page
 * reads the same greens, reds and ambers.
 *
 * Admin-only. User-facing relative-time copy (view-models, frontend)
 * has its own formatters and deliberately does not route through here.
 */
final class AdminUi
{
    public const OK    = 'ok';
    public const WARN  = 'warn';
    public const BAD   = 'bad';
    public const INFO  = 'info';
    public const MUTED = 'muted';

    private const TONES = [self::OK, self::WARN, self::BAD, self::INFO, self::MUTED];

    /**
     * Status pill. Returns escaped HTML; an unknown tone renders muted.
     */
    public static function badge(string $label, string $tone = self::MUTED): string
    {
        if (!in_array($tone, self::TONES, true)) {
            $tone = self::MUTED;
        }

        return sprintf(
            '<span class="bcc-badge bcc-badge--%1$s">%2$s</span>',
            esc_attr($tone),
            esc_html($label)
        );
    }

    /**
     * Single-unit compact interval: "45s", "12m", "3h", "2d" (floored).
     */
    public static function interval(int $seconds): string
    {
        $seconds = abs($seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h';
        }
        return intdiv($seconds, 86400) . 'd';
    }

    /**
     * Two-way relative time against now: "in 5m" / "5m ago"; "never"
     * for a missing (<= 0) timestamp.
     */
    public static function relative(int $timestamp, ?int $now = null): string
    {
        if ($timestamp <= 0) {
            return 'never';
        }

        $delta = $timestamp - ($now ?? time());
        $value = self::interval($delta);

        return $delta > 0 ? 'in ' . $value : $value . ' ago';
    }
}
