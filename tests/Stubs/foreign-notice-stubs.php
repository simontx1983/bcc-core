<?php
/**
 * Global WP stubs for ForeignNoticeFilterTest: a minimal WP_Hook-shaped
 * $wp_filter, remove_action, apply_filters, sanitize_key, wp_unslash.
 *
 * @package BCC\Core\Tests
 */

declare(strict_types=1);

if (!function_exists('apply_filters')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function apply_filters(string $hook, $value)
    {
        return $GLOBALS['__filters'][$hook] ?? $value;
    }
}

if (!function_exists('remove_action')) {
    /** @param mixed $fn */
    function remove_action(string $hook, $fn, int $priority = 10): bool
    {
        $callbacks = $GLOBALS['wp_filter'][$hook]->callbacks[$priority] ?? [];
        foreach ($callbacks as $id => $entry) {
            if ($entry['function'] === $fn) {
                unset($GLOBALS['wp_filter'][$hook]->callbacks[$priority][$id]);
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('wp_unslash')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function wp_unslash($value)
    {
        return $value;
    }
}
