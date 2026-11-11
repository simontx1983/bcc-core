<?php
/**
 * Global-namespace WP stubs for AdminMenuTest: apply_filters reads
 * $GLOBALS['__filters'], remove_submenu_page edits $GLOBALS['submenu'].
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

if (!function_exists('remove_submenu_page')) {
    function remove_submenu_page(string $parent, string $slug): void
    {
        foreach ($GLOBALS['submenu'][$parent] ?? [] as $i => $item) {
            if ($item[2] === $slug) {
                unset($GLOBALS['submenu'][$parent][$i]);
            }
        }
    }
}
