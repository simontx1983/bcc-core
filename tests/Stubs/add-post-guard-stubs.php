<?php
/**
 * Stubs for PeepSoAddPostGuardTest (F-361).
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\PeepSoActivity` and friends — classes
 * that exist for real whenever PeepSo is loaded. A declaration cannot be
 * undone and the first one wins for the rest of the process, so the consuming
 * test is `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * ⚠⚠ WHAT IS MODELLED, AND WHAT THAT PROVES. The listener bodies here are
 * FAITHFUL RE-IMPLEMENTATIONS of PeepSo's, cited line by line, including the
 * part that matters: they read `$_GET` first, then `$_POST`, then the raw
 * body, exactly as `PeepSoInput` does (peepso/classes/input.php:46-48,
 * :163-174). The code under test is the real guard.
 *
 * So these tests prove that the guard drops the right callbacks, keeps the
 * right ones, pins the request, and restores both — against readers that
 * behave the way PeepSo's do. They do NOT prove PeepSo's real listeners
 * behave as modelled; that is evidenced by the file:line citations in
 * {@see \BCC\Core\PeepSo\PeepSoAddPostGuard} and was read off PeepSo 9.0.5.5.
 * Stating the boundary beats implying there isn't one.
 *
 * Fixture ($GLOBALS['__bcc_apg']):
 *   wp_filter   => array<string, FakeWpHook>  the hook registry
 *   meta[<pid>] => array<string, mixed>       post-meta written by a listener
 *   ran         => list<string>               "Class::method" per listener run
 *   fetched     => list<string>               URLs the embed listener fetched
 *   anon        => list<int>                  anon_id values save_images saw
 *   albums      => list<string>               albums the album listener made
 *   deleted     => list<int>                  post ids PeepSo deleted
 *   add_post    => list<array>                spy log of add_post() calls
 */

declare(strict_types=1);

namespace {
    /**
     * Minimal stand-in for WordPress's WP_Hook: the only part either the
     * guard or WordPress itself relies on is the public `$callbacks` array,
     * shaped priority => id => ['function' => cb, 'accepted_args' => n].
     */
    if (!class_exists('FakeWpHook', false)) {
        class FakeWpHook
        {
            /** @var array<int, array<string, array{function: mixed, accepted_args: int}>> */
            public array $callbacks = [];
        }
    }

    if (!function_exists('__bcc_apg_hook_id')) {
        /** @param mixed $cb */
        function __bcc_apg_hook_id($cb): string
        {
            if (is_array($cb) && count($cb) === 2) {
                $owner = is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0];

                return $owner . '::' . (string) $cb[1];
            }

            return is_string($cb) ? $cb : spl_object_hash((object) $cb);
        }
    }

    // ⚠ `$GLOBALS['wp_filter']` is the ONE store, because that is what the
    // guard reads. Keeping a second copy under the fixture key would be a
    // chance for the two to disagree and for a test to pass against the copy.
    if (!function_exists('__bcc_apg_add')) {
        /** @param mixed $cb */
        function __bcc_apg_add(string $hook, $cb, int $priority = 10, int $args = 1): void
        {
            if (!isset($GLOBALS['wp_filter'][$hook])) {
                $GLOBALS['wp_filter'][$hook] = new FakeWpHook();
            }
            $GLOBALS['wp_filter'][$hook]->callbacks[$priority][__bcc_apg_hook_id($cb)] = [
                'function'      => $cb,
                'accepted_args' => $args,
            ];
            ksort($GLOBALS['wp_filter'][$hook]->callbacks);
        }
    }

    if (!function_exists('__bcc_apg_remove')) {
        /** @param mixed $cb */
        function __bcc_apg_remove(string $hook, $cb, int $priority = 10): void
        {
            if (!isset($GLOBALS['wp_filter'][$hook])) {
                return;
            }
            unset($GLOBALS['wp_filter'][$hook]->callbacks[$priority][__bcc_apg_hook_id($cb)]);
            if (($GLOBALS['wp_filter'][$hook]->callbacks[$priority] ?? null) === []) {
                unset($GLOBALS['wp_filter'][$hook]->callbacks[$priority]);
            }
        }
    }

    if (!function_exists('__bcc_apg_fire')) {
        function __bcc_apg_fire(string $hook, int $postId, int $actId): void
        {
            $registry = $GLOBALS['wp_filter'][$hook] ?? null;
            if (!$registry instanceof FakeWpHook) {
                return;
            }
            foreach ($registry->callbacks as $callbacks) {
                foreach ($callbacks as $entry) {
                    $fn = $entry['function'];
                    if (is_callable($fn)) {
                        $fn($postId, $actId);
                    }
                }
            }
        }
    }

    /**
     * `PeepSoInput`, modelled on peepso/classes/input.php.
     *
     * ⚠ The precedence is the point of this whole fixture:
     *   value()  $_GET → $_POST                      (:38-46)
     *   int()    $_GET → $_POST → raw php://input    (:46-48, :163-174)
     * The raw-body leg is what makes `unset()` insufficient and pinning
     * necessary, so it is modelled rather than skipped. `__bcc_apg_raw` plays
     * the part of `file_get_contents('php://input')`, which a unit test
     * cannot populate for real.
     */
    if (!class_exists('__BccApgInput', false)) {
        class __BccApgInput
        {
            /** @param mixed $default @return mixed */
            public function value(string $name, $default = '')
            {
                if (isset($_GET[$name])) {
                    return $_GET[$name];
                }

                return $_POST[$name] ?? $default;
            }

            public function int(string $name, int $default = 0): int
            {
                if (isset($_GET[$name])) {
                    return (int) $_GET[$name];
                }
                if (isset($_POST[$name])) {
                    return (int) $_POST[$name];
                }
                $raw = $GLOBALS['__bcc_apg']['raw'] ?? [];

                return isset($raw[$name]) ? (int) $raw[$name] : $default;
            }

            /** @param mixed $default @return mixed */
            public function raw(string $name, $default = null)
            {
                // ⚠ get_raw() at input.php:89 is an EMPTY stub, so raw()
                // ignores $_GET entirely. Modelled, because it is the reason
                // the embed vector needs $_POST and not a query string.
                return $_POST[$name] ?? $default;
            }
        }
    }

    // ── the listeners ───────────────────────────────────────────────────

    /** groups.php:1616 — the HIGH. Stamps the group, notifies its followers. */
    if (!class_exists('PeepSoGroupsPlugin', false)) {
        class PeepSoGroupsPlugin
        {
            public const MODULE_ID = 8;

            public function after_add_post(int $postId, int $actId): void
            {
                unset($actId);
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::after_add_post';

                $input     = new __BccApgInput();
                $groupId   = $input->int('group_id', 0);
                $moduleId  = $input->int('module_id', 0);

                if ((0 !== $groupId || 0 !== $moduleId) && self::MODULE_ID === $moduleId) {
                    $GLOBALS['__bcc_apg']['meta'][$postId]['peepso_group_id'] = $groupId;
                    $GLOBALS['__bcc_apg']['notified'][] = $groupId;
                }
            }
        }
    }

    /** pages.php:1764 — stamps the page and "post as page". */
    if (!class_exists('PeepSoPagesPlugin', false)) {
        class PeepSoPagesPlugin
        {
            public const MODULE_ID = 9;

            public function after_add_post(int $postId, int $actId): void
            {
                unset($actId);
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::after_add_post';

                $input    = new __BccApgInput();
                $pageId   = $input->int('page_id', 0);
                $moduleId = $input->int('module_id', 0);

                if ((0 !== $pageId || 0 !== $moduleId) && self::MODULE_ID === $moduleId) {
                    $GLOBALS['__bcc_apg']['meta'][$postId]['peepso_page_id'] = $pageId;
                    if ($input->int('post_as_page', 0) > 0) {
                        $GLOBALS['__bcc_apg']['meta'][$postId]['peepso_post_as_page'] = 1;
                    }
                }
            }
        }
    }

    /** tags.php:127 — the ONE content-driven listener. Must keep running. */
    if (!class_exists('PeepSoTags', false)) {
        class PeepSoTags
        {
            public function after_save_post(int $postId, int $actId): void
            {
                unset($postId, $actId);
                // Reads the post CONTENT, never the request.
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::after_save_post';
            }
        }
    }

    /** giphy.php:408 — request-driven, but the GIF writer drives it. */
    if (!class_exists('PeepSoGiphy', false)) {
        class PeepSoGiphy
        {
            public function after_add_post(int $postId, int $actId): void
            {
                unset($actId);
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::after_add_post';

                $input = new __BccApgInput();
                $giphy = (string) $input->value('giphy', '');
                if ($giphy !== '' && 'giphy' === $input->value('type', '')) {
                    $GLOBALS['__bcc_apg']['meta'][$postId]['peepso_giphy'] = $giphy;
                }
            }
        }
    }

    /** photosmodel.php:100 + peepsosharephotos.php:729/2212. */
    if (!class_exists('PeepSoSharePhotos', false)) {
        class PeepSoSharePhotos
        {
            public const MODULE_ID = 4;

            public function after_add_post(int $postId, int $actId): void
            {
                unset($actId);
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::after_add_post';

                $input = new __BccApgInput();
                $files = $input->value('files', []);
                if (is_array($files) && count($files) > 0 && 'photo' === $input->value('type', '')) {
                    $GLOBALS['__bcc_apg']['meta'][$postId]['photos'] = $files;

                    // save_images() — photosmodel.php:100. Copies the upload
                    // into ANOTHER member's folder when anon_id is supplied.
                    $anonId = $input->int('anon_id', 0);
                    if ($anonId > 0) {
                        $GLOBALS['__bcc_apg']['anon'][] = $anonId;
                    }
                }
            }

            public function after_add_post_album(int $postId, int $actId): void
            {
                unset($postId, $actId);
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::after_add_post_album';

                $input = new __BccApgInput();
                $photo = $input->value('photo', []);
                if (is_array($photo) && count($photo) > 0 && 'album' === $input->value('type', '')) {
                    $GLOBALS['__bcc_apg']['albums'][] = (string) $input->value('name', '');
                }
            }
        }
    }

    /** polls.php:241 and postbackgrounds.php:279 — request-driven post-meta. */
    if (!class_exists('PeepSoPolls', false)) {
        class PeepSoPolls
        {
            public function after_add_post(int $postId, int $actId = 0): void
            {
                unset($actId);
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::after_add_post';

                $input   = new __BccApgInput();
                $options = $input->value('options', []);
                if (is_array($options) && count($options) >= 2) {
                    $GLOBALS['__bcc_apg']['meta'][$postId]['select_options'] = $options;
                }
            }
        }
    }

    if (!class_exists('PeepSoPostBackgrounds', false)) {
        class PeepSoPostBackgrounds
        {
            public function after_add_post(int $postId, int $actId = 0): void
            {
                unset($actId);
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::after_add_post';

                $input      = new __BccApgInput();
                $background = $input->value('background', '');
                if ($background !== '' && $background !== null) {
                    $GLOBALS['__bcc_apg']['meta'][$postId]['peepso_post_background'] = $background;
                }
            }
        }
    }

    /**
     * PeepSoActivity: the write seam, plus the blind-SSRF listener.
     *
     * `add_post()` FIRES the hook, which is what makes these tests exercise
     * the guard end to end instead of asserting on a registry.
     */
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
             * @return int|false
             */
            public function add_post($owner, $author, $content, $extra = array())
            {
                $GLOBALS['__bcc_apg']['add_post'][] = [
                    'owner'   => (int) $owner,
                    'author'  => (int) $author,
                    'content' => (string) $content,
                    'extra'   => is_array($extra) ? $extra : [],
                    // What the request looked like AT THE MOMENT OF THE WRITE.
                    'get'     => $_GET,
                    'post'    => $_POST,
                ];

                $postId = 4242;
                __bcc_apg_fire('peepso_activity_after_add_post', $postId, 777);

                return $postId;
            }

            /** activity.php:5769 — reads `embed` from $_POST, then FETCHES it. */
            public function handle_embed_data(int $postId = 0, int $actId = 0): void
            {
                unset($postId, $actId);
                $GLOBALS['__bcc_apg']['ran'][] = self::class . '::handle_embed_data';

                $input = new __BccApgInput();
                if ('activity' !== $input->value('type', '')) {
                    return;
                }
                $url = $input->raw('embed');
                if (is_string($url) && $url !== '') {
                    $GLOBALS['__bcc_apg']['fetched'][] = $url;
                }
            }

            /** activity.php:777 — takes a WP_POST id; removes post + act rows. */
            public function delete_post($post_id): void
            {
                $GLOBALS['__bcc_apg']['deleted'][] = (int) $post_id;
            }
        }
    }

    if (!isset($GLOBALS['__bcc_apg_wpdb_installed'])) {
        $GLOBALS['__bcc_apg_wpdb_installed'] = true;
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
    // Shadowed INSIDE this namespace rather than globally: PHP resolves an
    // unqualified call to the current namespace first, so this reaches the
    // guard and the writers without touching the shared bootstrap.
    if (!\function_exists('BCC\Core\PeepSo\add_action')) {
        /** @param mixed $cb */
        function add_action(string $hook, $cb, int $priority = 10, int $args = 1): bool
        {
            \__bcc_apg_add($hook, $cb, $priority, $args);

            return true;
        }
    }

    if (!\function_exists('BCC\Core\PeepSo\remove_action')) {
        /** @param mixed $cb */
        function remove_action(string $hook, $cb, int $priority = 10): bool
        {
            \__bcc_apg_remove($hook, $cb, $priority);

            return true;
        }
    }

    if (!\function_exists('BCC\Core\PeepSo\get_post_meta')) {
        /** @return mixed */
        function get_post_meta($postId, $key = '', $single = false)
        {
            unset($single);

            return $GLOBALS['__bcc_apg']['meta'][(int) $postId][$key] ?? '';
        }
    }

    if (!\function_exists('BCC\Core\PeepSo\update_post_meta')) {
        function update_post_meta($postId, $key, $value): bool
        {
            $GLOBALS['__bcc_apg']['meta'][(int) $postId][$key] = $value;

            return true;
        }
    }

    if (!\function_exists('BCC\Core\PeepSo\get_user_meta')) {
        /** @return mixed */
        function get_user_meta($userId, $key = '', $single = false)
        {
            unset($userId, $key, $single);

            return '';
        }
    }

    if (!\function_exists('BCC\Core\PeepSo\do_action')) {
        function do_action($hook, ...$args): void
        {
            $GLOBALS['__bcc_apg']['actions'][] = (string) $hook;
            unset($args);
        }
    }
}

namespace BCC\Core\Log {
    // The guard logs a hijack; a unit run must not touch the filesystem.
    if (!\class_exists('BCC\Core\Log\Logger', false)) {
        class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_apg']['logged'][] = $message;
                unset($context);
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                unset($message, $context);
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
                unset($message, $context);
            }
        }
    }
}
