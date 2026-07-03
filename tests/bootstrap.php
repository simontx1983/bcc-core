<?php
/**
 * PHPUnit bootstrap for bcc-core unit tests.
 *
 * Deliberately minimal — pure-unit, no WordPress core:
 *   - Defines ABSPATH so production files' `if (!defined('ABSPATH')) exit;`
 *     guard lets them parse when autoloaded.
 *   - Registers Composer's autoloader (PSR-4 `BCC\Core\` -> src/).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/**
 * Minimal WP_Error stub for pure-unit tests that exercise SafeHttpClient's
 * SSRF-block branches without loading WordPress core. Only the surface the
 * tests read (get_error_code / get_error_message) is implemented. Guarded so
 * a real WordPress WP_Error (if the suite ever runs under wp core) wins.
 */
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;

        /**
         * @param string|int $code
         * @param string     $message
         * @param mixed      $data
         */
        public function __construct($code = '', string $message = '', $data = null)
        {
            $this->code    = (string) $code;
            $this->message = $message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

/**
 * WordPress's `add_filter` is invoked by SafeHttpClient::prepareArgs() (not by
 * the batch path, but the class references it). Provide a no-op so autoloading
 * + any incidental call is harmless in the pure-unit context.
 */
if (!function_exists('add_filter')) {
    /**
     * @param mixed $callback
     */
    function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

if (!function_exists('add_action')) {
    /**
     * @param mixed $callback
     */
    function add_action(string $hook, $callback, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}

if (!function_exists('__return_false')) {
    function __return_false(): bool
    {
        return false;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
