<?php

/**
 * ⚠⚠ SUBPROCESS-ONLY (#[RunTestsInSeparateProcesses]), required from
 * setUp(): it declares global WordPress functions, which would otherwise
 * reach every later test in the process.
 *
 * The post-meta API as WordPress shapes it, over the executing PdoWpdb:
 *   - get_post_meta() reads through a per-request cache: the FIRST read of a
 *     post loads ALL its meta from the table (update_meta_cache()), and later
 *     reads are served from that copy until wp_cache_delete($id, 'post_meta')
 *     drops it. A writer that skips the delete leaves a stale answer here,
 *     as it would in WordPress.
 *   - wp_cache_delete() records each call in $GLOBALS['__pmw_deletes'].
 *   - do_action() records each hook in $GLOBALS['__pmw_actions'].
 *   - Logger records each line in $GLOBALS['__pmw_log'] (declared before
 *     anything autoloads the real one, which needs WordPress).
 */

declare(strict_types=1);

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__pmw_log'][] = ['ERROR', $message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                $GLOBALS['__pmw_log'][] = ['WARNING', $message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                $GLOBALS['__pmw_log'][] = ['INFO', $message, $context];
            }
        }
    }
}

namespace {
    if (!function_exists('wp_cache_delete')) {
        /** @param int|string $key */
        function wp_cache_delete($key, string $group = ''): bool
        {
            $GLOBALS['__pmw_deletes'][] = [$key, $group];
            unset($GLOBALS['__pmw_cache'][$group][(string) $key]);

            return true;
        }
    }

    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            if (!isset($GLOBALS['__pmw_cache']['post_meta'][(string) $postId])) {
                global $wpdb;
                $all  = [];
                $sql  = $wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $postId);
                $wpdb->query($sql);
                foreach ($wpdb->last_result as $row) {
                    $all[(string) $row->meta_key][] = $row->meta_value;
                }
                $GLOBALS['__pmw_cache']['post_meta'][(string) $postId] = $all;
            }
            $values = $GLOBALS['__pmw_cache']['post_meta'][(string) $postId][$key] ?? [];

            return $single ? ($values[0] ?? '') : $values;
        }
    }

    if (!function_exists('do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__pmw_actions'][] = [$hook, $args];
        }
    }
}
