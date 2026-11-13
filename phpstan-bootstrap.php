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
