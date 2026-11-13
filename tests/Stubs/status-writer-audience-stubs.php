<?php
/**
 * Stubs for PeepSoStatusWriterDefaultAudienceTest (F-291).
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\PeepSoActivity`, a class that exists
 * for real whenever PeepSo is loaded; a declaration cannot be undone and the
 * first one wins for the rest of the process. The consuming test is
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * `add_post()` is a SPY as well as a stub: what the writer must get right is
 * the `$extra` it HANDS OVER, which no return value can show. PeepSo decides
 * `act_access` from `$extra['act_access']` if present and otherwise falls
 * back to the author's PROFILE level, so "did we pass it, and what" is the
 * whole behaviour under test.
 *
 * `get_user_meta` is shadowed inside `BCC\Core\PeepSo` rather than globally:
 * PHP resolves an unqualified call to the current namespace first, and a
 * global declaration would be the shared-WordPress-function trap this suite
 * warns about elsewhere.
 *
 * Fixture ($GLOBALS['__bcc_swa']):
 *   meta[<uid>]  => mixed  stored peepso_last_used_post_privacy (omit = unset)
 *   add_post     => list<array{owner: int, author: int, extra: array}>  spy log
 */

declare(strict_types=1);

namespace {
    if (!class_exists('PeepSoActivity', false)) {
        class PeepSoActivity
        {
            public const MODULE_ID = 1;

            private static ?self $instance = null;

            public static function get_instance(): self
            {
                return self::$instance ??= new self();
            }

            /**
             * @param array<string, mixed> $extra
             * @return int
             */
            public function add_post($owner, $author, $content, $extra = array())
            {
                unset($content);
                $GLOBALS['__bcc_swa']['add_post'][] = [
                    'owner'  => (int) $owner,
                    'author' => (int) $author,
                    'extra'  => is_array($extra) ? $extra : [],
                ];

                return 4242; // a plausible wp_post id
            }
        }
    }

    // Minimal $wpdb: resolveActIdForPost() runs AFTER add_post(), so it only
    // has to not fatal. It returns a non-zero act_id so create() reports
    // success and the test reads a realistic result shape.
    if (!isset($GLOBALS['__bcc_swa_wpdb_installed'])) {
        $GLOBALS['__bcc_swa_wpdb_installed'] = true;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                unset($args);

                return $sql;
            }

            /** @param string $sql */
            public function get_var($sql): string
            {
                unset($sql);

                return '777';
            }
        };
    }
}

namespace BCC\Core\PeepSo {
    if (!\function_exists('BCC\Core\PeepSo\get_user_meta')) {
        /** @return mixed */
        function get_user_meta($userId, $key = '', $single = false)
        {
            unset($single);
            if ($key !== 'peepso_last_used_post_privacy') {
                return '';
            }

            // '' for an untouched account, exactly as WordPress answers for
            // absent meta — which is the "member never chose" case.
            return $GLOBALS['__bcc_swa']['meta'][(int) $userId] ?? '';
        }
    }

    // attachToGroup() reads post meta on the group path.
    if (!\function_exists('BCC\Core\PeepSo\get_post_meta')) {
        /** @return mixed */
        function get_post_meta($postId, $key = '', $single = false)
        {
            unset($postId, $key, $single);

            return '';
        }
    }

    if (!\function_exists('BCC\Core\PeepSo\update_post_meta')) {
        function update_post_meta($postId, $key, $value): bool
        {
            unset($postId, $key, $value);

            return true;
        }
    }

    if (!\function_exists('BCC\Core\PeepSo\do_action')) {
        function do_action($hook, ...$args): void
        {
            unset($hook, $args);
        }
    }
}
