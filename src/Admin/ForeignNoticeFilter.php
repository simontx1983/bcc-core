<?php

declare(strict_types=1);

namespace BCC\Core\Admin;

/**
 * On BCC admin screens (?page=bcc-*), drop admin notices that do not come
 * from BCC code — PeepSo upsells, the core update nag, other plugins' nags
 * — so the page starts with its own content. BCC's own notices (env
 * banner, notification summary, missing-key warnings, the local demo
 * seeder) stay. Every other wp-admin screen is untouched: the dashboard,
 * Plugins, Updates etc. still show everything.
 *
 * "BCC code" = the callback is defined in a file under
 * wp-content/plugins/bcc-* or wp-content/mu-plugins/bcc-*. Callbacks whose
 * origin cannot be determined are kept (fail open: a notice we cannot
 * attribute is not hidden).
 *
 * Runs on `in_admin_header`, which fires before admin-header.php prints
 * the notice hooks. Escape hatch: add_filter('bcc_admin_hide_foreign_notices',
 * '__return_false').
 */
final class ForeignNoticeFilter
{
    private const NOTICE_HOOKS = ['admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices'];

    public static function register(): void
    {
        add_action('in_admin_header', [self::class, 'filter'], 1);
    }

    public static function filter(): void
    {
        if (!AdminTheme::isBccPage() || !apply_filters('bcc_admin_hide_foreign_notices', true)) {
            return;
        }

        global $wp_filter;
        foreach (self::NOTICE_HOOKS as $hook) {
            if (!isset($wp_filter[$hook]) || !is_object($wp_filter[$hook]) || !isset($wp_filter[$hook]->callbacks)) {
                continue;
            }
            /** @var array<int, array<string, array{function: mixed}>> $byPriority */
            $byPriority = $wp_filter[$hook]->callbacks;
            foreach ($byPriority as $priority => $callbacks) {
                foreach ($callbacks as $entry) {
                    $fn   = $entry['function'] ?? null;
                    $file = self::definingFile($fn);
                    if ($file !== null && !self::isBccFile($file) && is_callable($fn)) {
                        remove_action($hook, $fn, (int) $priority);
                    }
                }
            }
        }
    }

    /**
     * True when $file lives in a BCC plugin or BCC must-use plugin.
     */
    public static function isBccFile(string $file): bool
    {
        $path = str_replace('\\', '/', $file);
        return (bool) preg_match('#/(?:mu-)?plugins/bcc-[^/]*#i', $path);
    }

    /**
     * Source file of a hook callback, or null when it cannot be resolved.
     *
     * @param mixed $fn
     */
    public static function definingFile($fn): ?string
    {
        try {
            if ($fn instanceof \Closure) {
                $file = (new \ReflectionFunction($fn))->getFileName();
            } elseif (is_string($fn) && str_contains($fn, '::')) {
                [$class, $method] = explode('::', $fn, 2);
                $file = (new \ReflectionMethod($class, $method))->getFileName();
            } elseif (is_string($fn) && function_exists($fn)) {
                $file = (new \ReflectionFunction($fn))->getFileName();
            } elseif (is_array($fn) && count($fn) === 2 && (is_object($fn[0]) || is_string($fn[0])) && is_string($fn[1])) {
                $file = (new \ReflectionMethod($fn[0], $fn[1]))->getFileName();
            } elseif (is_object($fn) && method_exists($fn, '__invoke')) {
                $file = (new \ReflectionMethod($fn, '__invoke'))->getFileName();
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            // Reflecting 'Class::method' can autoload the class; a throwing
            // autoloader or a class file that errors must not break the page.
            // Treat as unattributable → the callback is kept.
            return null;
        }

        return is_string($file) && $file !== '' ? $file : null;
    }
}
