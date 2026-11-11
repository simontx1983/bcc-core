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
 * Developer pages (engineer diagnostics + destructive repair) are listed
 * only when wp-config defines `BCC_DEV_TOOLS` as exactly `true` — same
 * strict idiom as BCC_REPAIR_ENABLED. Otherwise their menu entries are
 * removed late (priority 999, same pass shape as {@see HideWpDefaults}).
 * remove_submenu_page() drops only the MENU ENTRY: the page stays
 * registered, so a direct URL (and every admin-post redirect back to it)
 * keeps working for manage_options users. This is decluttering, not an
 * access control — each page keeps its own capability / gate checks.
 *
 * Plugins contribute slugs through two filters:
 *   - bcc_admin_dev_pages    — hidden unless BCC_DEV_TOOLS === true.
 *   - bcc_admin_hidden_pages — always hidden (alias pages kept for old URLs).
 */
final class AdminMenu
{
    public const SYSTEM          = SystemHealthPage::PAGE_SLUG;
    public const SYSTEM_POSITION = 27;

    /** Submenu ordering inside BCC System. Gaps leave room for contributors. */
    public const ORDER_HEALTH    = 0;
    public const ORDER_API_KEYS  = 10;
    public const ORDER_CRON      = 20;
    public const ORDER_AUDIT     = 30;
    public const ORDER_DEV       = 80;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'pruneMenu'], 999);
    }

    public static function devToolsEnabled(): bool
    {
        return defined('BCC_DEV_TOOLS') && BCC_DEV_TOOLS === true;
    }

    public static function pruneMenu(): void
    {
        global $submenu;
        if (!is_array($submenu)) {
            return;
        }

        $hidden = self::slugs('bcc_admin_hidden_pages', []);
        if (!self::devToolsEnabled()) {
            $hidden = array_merge($hidden, self::slugs('bcc_admin_dev_pages', [DeveloperPage::PAGE_SLUG]));
        }
        if ($hidden === []) {
            return;
        }

        foreach ($submenu as $parent => $items) {
            foreach ((array) $items as $item) {
                $slug = isset($item[2]) ? (string) $item[2] : '';
                if ($slug !== '' && in_array($slug, $hidden, true)) {
                    remove_submenu_page((string) $parent, $slug);
                }
            }
        }
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
