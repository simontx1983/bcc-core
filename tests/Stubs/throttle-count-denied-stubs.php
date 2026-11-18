<?php
/**
 * F-290 doubles for ThrottleCountDeniedTest: just enough WordPress for
 * Throttle::allow() / release(), backed by $GLOBALS['__thr'].
 *
 *   options    array<string, mixed>   get_option / update_option
 *   cache      array<string, mixed>   wp_cache_* ("group:key" => value)
 *   ext_cache  bool                   wp_using_ext_object_cache()
 *   user_id    int                    get_current_user_id()
 *
 * The bcc-trust RateLimiter double is in throttle-ratelimiter-stub.php,
 * loaded only by the tests that want that backend present.
 * Required from setUp() of a separate-process test, never at file scope.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    /** @return mixed */
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['__thr']['options'][$name] ?? $default;
    }

    /** @param mixed $value */
    function update_option(string $name, $value, $autoload = null): bool
    {
        $GLOBALS['__thr']['options'][$name] = $value;
        return true;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    function apply_filters(string $hook, $value, ...$args)
    {
        return $value;
    }

    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['__thr']['user_id'] ?? 0);
    }

    function wp_using_ext_object_cache(): bool
    {
        return (bool) ($GLOBALS['__thr']['ext_cache'] ?? false);
    }

    /** @return mixed */
    function wp_cache_get(string $key, string $group = '', bool $force = false, &$found = null)
    {
        $k = $group . ':' . $key;
        $found = array_key_exists($k, $GLOBALS['__thr']['cache']);
        return $found ? $GLOBALS['__thr']['cache'][$k] : false;
    }

    /** @param mixed $value */
    function wp_cache_set(string $key, $value, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['__thr']['cache'][$group . ':' . $key] = $value;
        return true;
    }

    /** @param mixed $value */
    function wp_cache_add(string $key, $value, string $group = '', int $expire = 0): bool
    {
        $k = $group . ':' . $key;
        if (array_key_exists($k, $GLOBALS['__thr']['cache'])) {
            return false;
        }
        $GLOBALS['__thr']['cache'][$k] = $value;
        return true;
    }

    /** @return int|false */
    function wp_cache_incr(string $key, int $offset = 1, string $group = '')
    {
        $k = $group . ':' . $key;
        $GLOBALS['__thr']['cache'][$k] = (int) ($GLOBALS['__thr']['cache'][$k] ?? 0) + $offset;
        return $GLOBALS['__thr']['cache'][$k];
    }

    /** @return int|false */
    function wp_cache_decr(string $key, int $offset = 1, string $group = '')
    {
        $k = $group . ':' . $key;
        $GLOBALS['__thr']['cache'][$k] = (int) ($GLOBALS['__thr']['cache'][$k] ?? 0) - $offset;
        return $GLOBALS['__thr']['cache'][$k];
    }
}
