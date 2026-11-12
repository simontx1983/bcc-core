<?php

/**
 * Stubs for PeepSoVersionLock::currentStatus() and the admin notice.
 *
 * The WordPress surface is driven by $GLOBALS['__bcc_vl'] (reset per test):
 *   active   list<string>                      option active_plugins
 *   network  array<string, int>                site option active_sitewide_plugins
 *   multisite bool
 *   plugins  array<string, array<string,string>> get_plugins()
 *   can      bool                              current_user_can()
 *
 * Load only from #[RunTestsInSeparateProcesses] classes: it defines global
 * functions (and, per test, a `PeepSo` class).
 */

declare(strict_types=1);

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $name === 'active_plugins' ? ($GLOBALS['__bcc_vl']['active'] ?? $default) : $default;
    }
}

if (!function_exists('get_site_option')) {
    function get_site_option(string $name, mixed $default = false): mixed
    {
        return $name === 'active_sitewide_plugins' ? ($GLOBALS['__bcc_vl']['network'] ?? $default) : $default;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['__bcc_vl']['multisite'] ?? false);
    }
}

if (!function_exists('get_plugins')) {
    /** @return array<string, array<string, string>> */
    function get_plugins(): array
    {
        return $GLOBALS['__bcc_vl']['plugins'] ?? [];
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return (bool) ($GLOBALS['__bcc_vl']['can'] ?? false);
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, mixed $cb, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, mixed $cb, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

/** The installed set on the 2026-11-11 host, plus an inactive friends add-on. */
function bcc_vl_world(string $core = '9.0.5.5', string $addons = '9.0.5.3'): void
{
    $GLOBALS['__bcc_vl'] = [
        'active'  => [
            'peepso/peepso.php',
            'peepso-groups/groups.php',
            'peepso-messages/peepsomessages.php',
            'peepso-pages/pages.php',
            'peepso-photos/peepsosharephotos.php',
            'bcc-core/bcc-core.php',
        ],
        'network'   => [],
        'multisite' => false,
        'can'       => true,
        'plugins'   => [
            'peepso/peepso.php'                   => ['Name' => 'PeepSo Foundation', 'Version' => $core],
            'peepso-groups/groups.php'            => ['Name' => 'PeepSo Groups', 'Version' => $addons],
            'peepso-messages/peepsomessages.php'  => ['Name' => 'PeepSo Messages', 'Version' => $addons],
            'peepso-pages/pages.php'              => ['Name' => 'PeepSo Pages', 'Version' => $addons],
            'peepso-photos/peepsosharephotos.php' => ['Name' => 'PeepSo Photos', 'Version' => $addons],
            'peepso-friends/peepsofriends.php'    => ['Name' => 'PeepSo Friends', 'Version' => '8.0.0.0'],
            'bcc-core/bcc-core.php'               => ['Name' => 'BCC Core', 'Version' => '1.0.0'],
        ],
    ];
}
