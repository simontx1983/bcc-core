<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * BCC wp-admin menu layout + developer-tools gating.
 *
 * Three top-level menus, one per audience:
 *   - BCC Moderation (bcc-trust, pos 25) — queues an admin works daily.
 *   - BCC On-Chain   (bcc-trust, pos 26) — collections, chains, indexer.
 *   - BCC System     (bcc-core,  pos 27) — health, keys, cron, audit log,
 *                                          and the Developer group.
 *
 * HIDDEN PAGES. A page that should be reachable by URL but not listed —
 * developer pages while `BCC_DEV_TOOLS` is not exactly `true` (same strict
 * idiom as BCC_REPAIR_ENABLED), and alias pages kept for old links — is
 * registered with an EMPTY parent via {@see parentFor()}. That is WP's
 * supported "page without a menu entry": it stays in $_registered_pages,
 * so direct URLs and admin-post redirects keep working. (Removing the
 * entry with remove_submenu_page() instead makes WP refuse the page with
 * "Sorry, you are not allowed" — that is why this does not do that.)
 * This is decluttering, not access control: every page keeps its own
 * capability / gate checks.
 *
 * ORDER. add_submenu_page()'s $position applies at insert time, so the
 * final order would depend on which plugin's admin_menu callback ran
 * first. {@see sortMenus()} runs last and orders each BCC menu by an
 * explicit slug list (filter `bcc_admin_menu_order`), also dropping the
 * duplicate first item WP auto-inserts when a submenu registers before
 * the landing page.
 *
 * Filters:
 *   - bcc_admin_dev_pages    — slugs hidden unless BCC_DEV_TOOLS === true.
 *   - bcc_admin_hidden_pages — slugs always hidden (alias pages).
 *   - bcc_admin_menu_order   — array<parent slug, list<child slug>>.
 */
final class AdminMenu
{
    public const SYSTEM          = SystemHealthPage::PAGE_SLUG;
    public const SYSTEM_POSITION = 27;

    /** BCC System order; contributors' slugs slot in by filter. */
    private const SYSTEM_ORDER = [
        SystemHealthPage::PAGE_SLUG,
        ApiKeysPage::PAGE_SLUG,
        CronPage::PAGE_SLUG,
        'bcc-sessions',
        DeveloperPage::PAGE_SLUG,
        'bcc-trust-debug',
        'bcc-system-repair',
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'sortMenus'], 1000);
    }

    public static function devToolsEnabled(): bool
    {
        return defined('BCC_DEV_TOOLS') && BCC_DEV_TOOLS === true;
    }

    /**
     * Parent slug to register $slug under: $parent normally, '' (listed
     * nowhere, still reachable by URL) when the page is hidden.
     */
    public static function parentFor(string $parent, string $slug): string
    {
        return self::isHidden($slug) ? '' : $parent;
    }

    public static function isHidden(string $slug): bool
    {
        if (in_array($slug, self::slugs('bcc_admin_hidden_pages', []), true)) {
            return true;
        }
        return !self::devToolsEnabled()
            && in_array($slug, self::slugs('bcc_admin_dev_pages', [DeveloperPage::PAGE_SLUG]), true);
    }

    public static function sortMenus(): void
    {
        global $submenu;
        if (!is_array($submenu)) {
            return;
        }

        $orders = apply_filters('bcc_admin_menu_order', [self::SYSTEM => self::SYSTEM_ORDER]);
        if (!is_array($orders)) {
            return;
        }

        foreach ($orders as $parent => $order) {
            $parent = (string) $parent;
            if (!isset($submenu[$parent]) || !is_array($submenu[$parent]) || !is_array($order)) {
                continue;
            }
            $submenu[$parent] = self::ordered($submenu[$parent], array_values(array_map('strval', $order)));
        }
    }

    /**
     * Dedupe by slug (the LAST registration wins — WP's auto-inserted
     * parent copy always comes first), then stable-sort by $order;
     * slugs not in $order keep their relative order after the listed ones.
     *
     * @param array<int|string, mixed> $items
     * @param list<string>             $order
     * @return list<mixed>
     */
    public static function ordered(array $items, array $order): array
    {
        $bySlug = [];
        foreach ($items as $item) {
            $slug = is_array($item) && isset($item[2]) ? (string) $item[2] : '';
            if ($slug === '') {
                continue;
            }
            unset($bySlug[$slug]);           // keep the last, at its own position
            $bySlug[$slug] = $item;
        }

        $rank = array_flip($order);
        $seq  = array_flip(array_keys($bySlug));
        $keys = array_keys($bySlug);
        usort($keys, static function (string $a, string $b) use ($rank, $seq): int {
            $ra = $rank[$a] ?? PHP_INT_MAX;
            $rb = $rank[$b] ?? PHP_INT_MAX;
            return $ra === $rb ? $seq[$a] <=> $seq[$b] : $ra <=> $rb;
        });

        return array_map(static fn (string $k) => $bySlug[$k], $keys);
    }

    /**
     * @param non-empty-string $filter
     * @param list<string> $default
     * @return list<string>
     */
    private static function slugs(string $filter, array $default): array
    {
        $slugs = apply_filters($filter, $default);
        if (!is_array($slugs)) {
            return $default;
        }
        return array_values(array_filter(array_map('strval', $slugs), static fn (string $s): bool => $s !== ''));
    }
}
