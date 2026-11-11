<?php
/**
 * Global-namespace WP stubs for AdminMenuTest: apply_filters reads
 * $GLOBALS['__filters'], tests seed $GLOBALS['submenu'] directly.
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
