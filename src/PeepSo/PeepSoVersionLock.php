<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo;

/**
 * F-176 (2): READ-ONLY check that PeepSo core and its active add-ons agree
 * on version, by PeepSo's own rule.
 *
 * ── WHY ────────────────────────────────────────────────────────────────
 * Every PeepSo add-on boots only if `PeepSo::ready(self::PLUGIN_VERSION)`
 * is true. ready() truncates core's and the add-on's versions to the first
 * N dot-segments (option `version_lock`, 1–3, default 3) and compares them.
 * On a mismatch the add-on is "VERSION LOCKED": its hooks and classes never
 * register, with no fatal. On 2026-11-11 core auto-updated to 9.1.1.0 while
 * the add-ons stayed on 9.0.5.x, so Groups, Pages and Messages silently
 * stopped working, and "create community" failed with a generic error.
 *
 * ── WHY THE RULE IS MIRRORED, NOT CALLED ───────────────────────────────
 * ready() returns NULL under WP-CLI (it bypasses the lock there), so a CLI
 * probe through it would read every add-on as locked, and the add-ons'
 * own CLI behaviour looks healthy. The comparison is therefore reproduced
 * here (truncate, compare) and pinned by tests against ready()'s cases;
 * the lock depth itself is still read from PeepSo's own option.
 *
 * ⚠ Nothing here repairs, deactivates or updates anything.
 */
final class PeepSoVersionLock
{
    public const CORE_PLUGIN  = 'peepso/peepso.php';
    public const DEFAULT_LOCK = 3;

    /** ready(): any value outside 1, 2, 3 means 3. */
    public static function normaliseLock(mixed $raw): int
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $n = (int) $raw;
            if ($n >= 1 && $n <= 3) {
                return $n;
            }
        }

        return self::DEFAULT_LOCK;
    }

    /** ready(): keep the first $lock dot-segments. */
    public static function truncate(string $version, int $lock): string
    {
        return implode('.', array_slice(explode('.', $version), 0, $lock));
    }

    public static function matches(string $coreVersion, string $addonVersion, int $lock): bool
    {
        return self::truncate($coreVersion, $lock) === self::truncate($addonVersion, $lock);
    }

    /**
     * @param array<string, array{name: string, version: string}> $addons active add-ons, keyed by plugin file
     * @return list<array{plugin: string, name: string, version: string}>
     */
    public static function mismatches(string $coreVersion, array $addons, int $lock): array
    {
        $out = [];
        foreach ($addons as $file => $addon) {
            if (!self::matches($coreVersion, $addon['version'], $lock)) {
                $out[] = ['plugin' => (string) $file, 'name' => $addon['name'], 'version' => $addon['version']];
            }
        }

        return $out;
    }

    /**
     * The live state, or null when PeepSo core is not active (nothing to
     * compare). Add-ons = ACTIVE plugins in a `peepso-*` directory; an
     * inactive or deleted add-on is not considered.
     *
     * @return array{core_version: string, lock: int, mismatches: list<array{plugin: string, name: string, version: string}>}|null
     */
    public static function currentStatus(): ?array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        $active = array_values(array_unique(array_map('strval', $active)));

        if (!in_array(self::CORE_PLUGIN, $active, true)) {
            return null;
        }

        $installed = get_plugins();

        // The constant is exactly what ready() compares against; the
        // header is the same value and the fallback.
        $coreVersion = class_exists('PeepSo', false) && defined('PeepSo::PLUGIN_VERSION')
            ? (string) constant('PeepSo::PLUGIN_VERSION')
            : (string) ($installed[self::CORE_PLUGIN]['Version'] ?? '');
        if ($coreVersion === '') {
            return null;
        }

        $addons = [];
        foreach ($active as $file) {
            if ($file === self::CORE_PLUGIN || !str_starts_with($file, 'peepso-') || !isset($installed[$file])) {
                continue;
            }
            $addons[$file] = [
                'name'    => (string) ($installed[$file]['Name'] ?? $file),
                'version' => (string) ($installed[$file]['Version'] ?? ''),
            ];
        }

        // PeepSo's own option, read through PeepSo (no stubs here, hence
        // the callable form).
        $getOption = ['PeepSo', 'get_option_new'];
        $rawLock   = class_exists('PeepSo', false) && is_callable($getOption)
            ? call_user_func($getOption, 'version_lock')
            : null;
        $lock = self::normaliseLock($rawLock);

        return [
            'core_version' => $coreVersion,
            'lock'         => $lock,
            'mismatches'   => self::mismatches($coreVersion, $addons, $lock),
        ];
    }
}
