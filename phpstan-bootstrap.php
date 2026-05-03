<?php
/**
 * PHPStan bootstrap for bcc-core — provides stubs for optional-plugin
 * classes referenced from this plugin's source.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 4) . '/');
}

// PeepSo plugin stubs (referenced from src/PeepSo/*.php)
if (!class_exists('PeepSoActivity')) {
    class PeepSoActivity
    {
        public const MODULE_ID = 1;
        public static function get_instance(): self { return new self(); }
        /** @return int|false */
        public function add_post(int $post_id, int $user_id, string $kind = '') { return false; }
    }
}
