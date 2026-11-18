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
        /**
         * ⚠ CORRECTED 2026-11-12 (F-291). This stub was
         * `add_post(int $post_id, int $user_id, string $kind = '')`, which
         * was wrong twice over and is worth spelling out, because a stub is
         * read as documentation:
         *
         *   - it OMITTED `$extra`, so passing the array PeepSo actually
         *     supports was reported as an arity error;
         *   - its parameter NAMES misdescribed the call. The first argument
         *     is the wall OWNER's user id and the second the AUTHOR's user
         *     id — not a post id — and the third is the post CONTENT, not a
         *     "kind". Anyone trusting those names would have written a
         *     silently wrong call.
         *
         * Real signature (peepso/activity/classes/activity.php:135):
         * `add_post($owner, $author, $content, $extra = array())`.
         * `$extra['act_access']` is what sets the row's privacy level; when
         * it is absent PeepSo falls back to the AUTHOR's profile
         * accessibility and then overwrites
         * `peepso_last_used_post_privacy` with whatever it used.
         *
         * @param array<string, mixed> $extra
         * @return int|false
         */
        public function add_post(int $owner, int $author, string $content, array $extra = []) { return false; }
        /**
         * @param array<string, mixed> $extra
         * @return int|false
         */
        public function add_comment(int $parent_post_id, int $author_id, string $content, array $extra = []) { return false; }
        /**
         * ⚠ Takes a WP_POST ID, not an act_id, despite PeepSo's own
         * parameter name — it queries `wp_posts` by it
         * (peepso/activity/classes/activity.php:777) and deletes the
         * activity row(s) alongside the post. That matters because
         * `wp_delete_post()` does NOT remove the activity row (PeepSo's
         * `before_delete_post` cleanup at peepso.php:2887 only fires for
         * BLOG posts), so this is the only call that leaves nothing behind.
         *
         * ⚠ It also deletes nested replies and every row whose
         * `act_repost_id` matches — harmless on a post created moments ago,
         * destructive on an established one. See
         * {@see \BCC\Core\PeepSo\PeepSoCommentWriter} on why the comment
         * path uses `wp_trash_post` instead.
         *
         * @param int $post_id
         * @return void
         */
        public function delete_post($post_id) {}
    }
}

// Referenced from src/PeepSo/PeepSoMessageWriter.php.
//
// ⚠ Added for F-361. Without it PHPStan cannot infer what these calls
// return, and a generic helper wrapping them ("Unable to resolve the
// template type TResult") fails level 8 — so the stub is what makes the
// guard usable here, not a formality. Return types are taken from how the
// writer narrows each result, which is the only contract we actually rely on:
//
//   create_new_conversation  is_wp_error($r) || $r === false, then (int) $r
//   add_to_conversation      $r === false || !is_int($r)
//   get_conversation_between $r !== null && (int) $r > 0
//   get_root_conversation    (int) $r
//
// ⚠⚠ Neither write method calls `PeepSoActivity::add_post()`. Both fire
// `peepso_activity_after_add_post` DIRECTLY
// (peepso-messages/classes/messagesmodel.php:321 and :500), so the
// request-driven listener family runs on a message send without the function
// that normally fires that hook. {@see \BCC\Core\PeepSo\PeepSoAddPostGuard}.
if (!class_exists('PeepSoMessagesModel')) {
    class PeepSoMessagesModel
    {
        /**
         * @param list<int> $participants
         * @return int|false|\WP_Error
         */
        public function create_new_conversation(int $author_id, string $content, string $title = '', array $participants = []) { return false; }
        /** @return int|false */
        public function add_to_conversation(int $author_id, int $root_msg_id, string $content) { return false; }
        /** @return int|string|null */
        public function get_conversation_between(int $user_a, int $user_b) { return null; }
        /** @return int|string */
        public function get_root_conversation(int $message_id) { return 0; }
    }
}

if (!class_exists('PeepSoSharePhotos')) {
    class PeepSoSharePhotos
    {
        public const MODULE_ID = 4;
    }
}

// Referenced from src/PeepSo/PeepSoReactionWriter.php.
if (!class_exists('PeepSoReactionsModel')) {
    class PeepSoReactionsModel
    {
        /** @var mixed Populated by init() from the activity row; null when missing. */
        public $act_external_id;
        /** @param int|null $act_id */
        public function init($act_id = null): void {}
        public function user_reaction_set(int $react_id): void {}
        public function user_reaction_reset(bool $is_delete = true): void {}
    }
}

// Referenced from src/PeepSo/PeepSoMediaCache.php (avatar/cover resolution).
if (!class_exists('PeepSoUser')) {
    class PeepSoUser
    {
        public static function get_instance(int $id = 0): self { return new self(); }
        public function get_avatar(string $suffix = 'full'): string { return ''; }
        public function has_cover(): bool { return false; }
        public function get_cover(int $size = 0): string { return ''; }
    }
}
