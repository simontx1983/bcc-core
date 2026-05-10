<?php
/**
 * PeepSoGifWriter — thin wrapper around PeepSo's Giphy write path.
 *
 * BCC must NOT INSERT directly into peepso_activities OR write the
 * `peepso_giphy` post_meta on the wp_post (single-graph rule, mirrors
 * PeepSoPhotoWriter / PeepSoCommentWriter / PeepSoReactionWriter).
 * PeepSo owns the write path: `PeepSoGiphy::after_add_post`
 * (peepso/classes/giphy.php:409) reads `$_POST['type']==='giphy'` +
 * `$_POST['giphy']` from a fresh add_post call and persists the URL to
 * `post_meta peepso_giphy` after validating it contains "giphy.com"
 * (peepso/classes/giphy.php:316).
 *
 * The flow is functionally identical to PeepSoPhotoWriter — drive
 * PeepSo's hook+filter chain via $_POST superglobals, restore in
 * try/finally. The differences vs the photo writer:
 *
 *   - No file staging. The GIF stays on Giphy's CDN; we persist only
 *     the URL.
 *   - No image processing pipeline. PeepSo's after_add_post hook
 *     short-circuits when `$_POST['giphy']` is set — it skips the
 *     photo path entirely and writes the GIF post_meta instead.
 *   - act_module_id is NOT stamped to PeepSoSharePhotos::MODULE_ID (4)
 *     for GIF posts. PeepSo's `peepso_activity_insert_data` filter
 *     only stamps module 4 for `type=photo`. For `type=giphy` the
 *     filter doesn't fire and the activity row keeps the default
 *     `act_module_id = PeepSoActivity::MODULE_ID` (1, status). GIF
 *     posts therefore look like status posts at the activity layer
 *     and get discriminated post-hoc by the `peepso_giphy` post_meta.
 *     FeedRankingService::hydrateBodies handles the kind override.
 *
 * The `$_POST` manipulation is the documented integration surface
 * PeepSo's own AJAX layer uses (peepso/classes/giphy.php:411-414).
 * The try/finally restores prior values so a request never leaks
 * Giphy state to a later call within the same request lifecycle.
 *
 * V1 scope: single GIF per post, owner==author (self wall). Multi-GIF
 * isn't a real product (one GIF expresses one feeling); group/page
 * walls are V2+.
 *
 * @package BCC\Core\PeepSo
 * @since v1.5 (2026-05, Phase 1c GIF picker)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoGifWriter
{
    /**
     * Mandatory URL substring — mirrors PeepSo's own check at
     * peepso/classes/giphy.php:316 (`strpos($giphy, 'giphy.com') === false`).
     * A stricter regex would diverge from PeepSo's own behavior and
     * potentially reject URLs PeepSo accepts (e.g. media subdomains,
     * future Giphy CDN paths). Match PeepSo's posture exactly.
     */
    private const REQUIRED_URL_SUBSTRING = 'giphy.com';

    /**
     * Create a GIF post on $authorId's own wall.
     *
     * Return shape mirrors PeepSoStatusWriter / PeepSoPhotoWriter:
     *   - ['ok' => true,  'post_id' => int, 'act_id' => int]    success
     *   - ['ok' => false, 'reason' => 'forbidden']         authorId<=0
     *   - ['ok' => false, 'reason' => 'unavailable']       PeepSo deactivated
     *   - ['ok' => false, 'reason' => 'invalid_url']       URL doesn't contain giphy.com
     *   - ['ok' => false, 'reason' => 'persist_failed']    add_post returned 0/false OR resolve query came back empty
     *
     * Group-wall variant: when $groupId > 0 the caller has already
     * verified existence + viewer membership upstream (PostsService
     * via {@see \BCC\Trust\Core\Services\GroupsService::resolveGroupAccess}).
     * After the post is persisted we stamp `peepso_group_id` post-meta
     * and fire `peepso_groups_new_post` via
     * {@see PeepSoStatusWriter::attachToGroup} — same uniform group-
     * attach path the status / photo writers use.
     *
     * @return array{ok: true, post_id: int, act_id: int}|array{ok: false, reason: string}
     */
    public static function createSelfGifPost(int $authorId, string $url, string $caption, int $groupId = 0): array
    {
        if ($authorId <= 0) {
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        if (!class_exists('PeepSoActivity') || !class_exists('PeepSoGiphy')) {
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        // ─── URL validation — match PeepSo's own check exactly ─────
        // peepso/classes/giphy.php:316,343,416 all use `strpos === false`.
        // A stricter regex would diverge.
        $urlTrimmed = trim($url);
        if ($urlTrimmed === '' || strpos($urlTrimmed, self::REQUIRED_URL_SUBSTRING) === false) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }

        // ─── Drive PeepSo's write path via $_POST ────────────────────
        // The `after_add_post` hook (peepso/classes/giphy.php:409) reads
        // these keys post-add_post. We capture the pre-call state with
        // `array_key_exists` so the restore can faithfully distinguish
        // "key was absent" from "key was present with null".
        $hadType    = array_key_exists('type', $_POST);
        $hadGiphy   = array_key_exists('giphy', $_POST);
        $prevType   = $hadType  ? $_POST['type']  : null;
        $prevGiphy  = $hadGiphy ? $_POST['giphy'] : null;

        $_POST['type']  = 'giphy';
        $_POST['giphy'] = $urlTrimmed;

        try {
            // PeepSo's add_post may reject strictly empty content, but
            // the giphy plugin's `activity_allow_empty_content` filter
            // (peepso/classes/giphy.php:276) explicitly allows empty
            // when `$_POST['giphy']` is set. So we can pass through the
            // user's caption verbatim — a single space pad isn't needed.
            $captionTrimmed = trim($caption);

            $postId = \PeepSoActivity::get_instance()->add_post(
                $authorId,
                $authorId,
                $captionTrimmed
            );
        } finally {
            self::restorePostKey('type',  $hadType,  $prevType);
            self::restorePostKey('giphy', $hadGiphy, $prevGiphy);
        }

        if ($postId === false || (int) $postId <= 0) {
            return ['ok' => false, 'reason' => 'persist_failed'];
        }
        $postId = (int) $postId;

        // ─── Resolve act_id ──────────────────────────────────────────
        // GIF posts share act_module_id=1 with status posts (PeepSo's
        // giphy plugin doesn't stamp module 4 — only the photos plugin
        // does). Look up by (act_external_id=postId, act_module_id=1).
        $actId = self::resolveActIdForPost($postId);
        if ($actId <= 0) {
            return ['ok' => false, 'reason' => 'persist_failed'];
        }

        if ($groupId > 0) {
            // Reuse the canonical group-attach helper (per §11) rather
            // than duplicating the post-meta + action-fire pair.
            PeepSoStatusWriter::attachToGroup($postId, $groupId);
        }

        return [
            'ok'      => true,
            'post_id' => $postId,
            'act_id'  => $actId,
        ];
    }

    /**
     * Reset a $_POST key to its pre-call state. `$wasPresent` is the
     * `array_key_exists` boolean captured before the call — without
     * it, "absent" and "present-with-null" are indistinguishable from
     * a `null === ?` check, which would silently unset a key that the
     * caller had explicitly null'd.
     *
     * Duplicated from PeepSoPhotoWriter::restorePostKey (~6 lines).
     * Acceptable per §11 on second occurrence; if a third writer ever
     * adds the same pattern, extract to a shared `PostSuperglobalGuard`
     * helper at that point.
     */
    private static function restorePostKey(string $key, bool $wasPresent, mixed $previous): void
    {
        if (!$wasPresent) {
            unset($_POST[$key]);
            return;
        }
        $_POST[$key] = $previous;
    }

    /**
     * Resolve the act_id PeepSo just inserted by reading
     * peepso_activities back. Same lookup shape as
     * PeepSoStatusWriter::resolveActIdForPost (which also matches
     * act_module_id = PeepSoActivity::MODULE_ID = 1) — GIF posts
     * share that module ID with status posts.
     */
    private static function resolveActIdForPost(int $postId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_activities';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT act_id FROM {$table}
              WHERE act_external_id = %d
                AND act_module_id   = %d
              LIMIT 1",
            $postId,
            \PeepSoActivity::MODULE_ID
        ));
    }
}
