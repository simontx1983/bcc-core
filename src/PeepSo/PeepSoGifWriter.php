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
 * (peepso/classes/giphy.php:316). That substring check is too weak, so
 * this writer first requires an https giphy.com / *.giphy.com host
 * (BCC\Core\Security\GiphyUrl).
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
     * Create a GIF post on $authorId's own wall.
     *
     * Return shape mirrors PeepSoStatusWriter / PeepSoPhotoWriter:
     *   - ['ok' => true,  'post_id' => int, 'act_id' => int]    success
     *   - ['ok' => false, 'reason' => 'forbidden']         authorId<=0
     *   - ['ok' => false, 'reason' => 'unavailable']       PeepSo deactivated
     *   - ['ok' => false, 'reason' => 'invalid_url']       not an https giphy.com / *.giphy.com URL (GiphyUrl::isAllowed)
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
    public static function createSelfGifPost(
        int $authorId,
        string $url,
        string $caption,
        int $groupId = 0,
        string $visibility = 'members_only'
    ): array {
        if ($authorId <= 0) {
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        if (!class_exists('PeepSoActivity') || !class_exists('PeepSoGiphy')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'gif_writer_create');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        // ─── URL validation — host check, STRICTER than PeepSo ───────
        // PeepSo's own check (peepso/classes/giphy.php:316) is a substring
        // test, which accepts https://evil.example/t.gif?x=giphy.com; the
        // stored URL is then rendered as an <img> for every viewer. Its
        // check still runs downstream, but ours must pass first: https,
        // host giphy.com or *.giphy.com. Shared with bcc-trust's comment
        // GIFs via GiphyUrl so the two paths cannot drift.
        $urlTrimmed = trim($url);
        if (!\BCC\Core\Security\GiphyUrl::isAllowed($urlTrimmed)) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }

        // ⚠⚠ F-361 — the write goes through the GUARD, never straight to
        // add_post. PeepSo's `peepso_activity_after_add_post` listeners read
        // request input and carry no authorization of their own, so the guard
        // pins the request for the duration of the write: only BCC's own
        // arguments reach them. {@see PeepSoAddPostGuard}.
        //
        // @mentions are NOT kept (F-423): BCC's own NotificationDispatcher
        // notifies mentionees, gated on the post's audience. See the class
        // note on PeepSoTags in {@see PeepSoAddPostGuard}.
        //
        // `PeepSoGiphy::after_add_post` is kept here and nowhere else: it is
        // request-driven, but THIS writer is the thing driving it.
        //
        // ⚠ The two keys this writer drives are PINNED by the guard rather
        // than assigned here, so a query string cannot outrank them and the
        // raw body cannot supply them. The guard restores the request
        // afterwards, which is why this writer no longer carries its own
        // capture/restore pair — the §11 extraction its `restorePostKey()`
        // docblock pre-registered as `PostSuperglobalGuard`.
        //
        // PeepSo's add_post may reject strictly empty content, but the giphy
        // plugin's `activity_allow_empty_content` filter
        // (peepso/classes/giphy.php:276) explicitly allows empty when
        // `$_POST['giphy']` is set. So we pass the user's caption verbatim —
        // no single-space pad needed.
        $postId = PeepSoAddPostGuard::around(
            [PeepSoAddPostGuard::KEEP_GIPHY],
            ['type' => 'giphy', 'giphy' => $urlTrimmed],
            static fn () => \PeepSoActivity::get_instance()->add_post(
                $authorId,
                $authorId,
                trim($caption)
            )
        );

        if ($postId === false || (int) $postId <= 0) {
            return ['ok' => false, 'reason' => 'persist_failed'];
        }
        $postId = (int) $postId;

        // Backstop for a detach that did not work: if a listener stamped this
        // post anyway, destroy it and fail rather than serve it as
        // legitimate. ⚠ Runs BEFORE attachToGroup(), which is where BCC
        // applies its OWN, gated stamp — so a stamp seen here is always
        // someone else's. ⚠ It cannot un-send a notification that listener
        // already fired; the detach above is the fix, this is the net.
        $hijacked = PeepSoAddPostGuard::failClosedIfHijacked($postId, $groupId);
        if ($hijacked !== null) {
            return ['ok' => false, 'reason' => $hijacked];
        }

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
            PeepSoStatusWriter::attachToGroup($postId, $groupId, $visibility);
        }

        return [
            'ok'      => true,
            'post_id' => $postId,
            'act_id'  => $actId,
        ];
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
