<?php
/**
 * PeepSoStatusWriter — thin wrapper around PeepSo's official status
 * write API per the §C2 / §A3 single-graph rule.
 *
 * BCC must NOT INSERT directly into peepso_activities for native
 * status posts — PeepSo owns the write path:
 *   - creates the peepso-activity-status CPT row (via wp_insert_post)
 *   - inserts the activity_stream row (act_module_id=PeepSoActivity::MODULE_ID,
 *     stored as the integer 1 — NOT the string 'status'; act_module_id is
 *     a numeric column despite BCC-owned modules using string keys like
 *     'review'/'pull_batch')
 *   - fires `peepso_activity_after_add_post`
 *   - dispatches notifications (when owner != author)
 *   - bookkeeping for post-meta + activity-stream cache
 *
 * This wrapper delegates to `PeepSoActivity::add_post()` (the same
 * method PeepSo's own Compose UI calls) and surfaces the resulting
 * wp_post ID + act_id pair to callers. Callers may then fire BCC's
 * own §A3 event (`bcc_post_created`) layered on top.
 *
 * V1 scope: status posts on the actor's own wall only (owner ===
 * author). Wall-of-other support is deferred — the Composer surface
 * doesn't expose that path until §D3 lands.
 *
 * @package BCC\Core\PeepSo
 * @since V1 (2026-04, §D1 Composer status posts)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoStatusWriter
{
    /**
     * Return shape:
     *   - ['ok' => true,  'post_id' => int, 'act_id' => int]    success
     *   - ['ok' => false, 'reason' => 'unavailable']            PeepSo deactivated
     *   - ['ok' => false, 'reason' => 'forbidden']              PeepSo permission check failed
     *   - ['ok' => false, 'reason' => 'empty_content']          stripped content was empty
     *   - ['ok' => false, 'reason' => 'persist_failed']         wp_insert_post or activity insert failed
     */
    /**
     * Create a status post on $authorId's own wall.
     *
     * The wp_post is created with post_type = peepso-activity-status
     * and post_status = publish. The activity row is inserted with
     * act_module_id = PeepSoActivity::MODULE_ID (integer 1) and
     * act_external_id pointing at the post id — the same shape PeepSo's
     * own UI produces.
     *
     * @return array{ok: true, post_id: int, act_id: int}|array{ok: false, reason: string}
     */
    public static function createSelfStatus(int $authorId, string $content): array
    {
        if ($authorId <= 0) {
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        if (!class_exists('PeepSoActivity')) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }
        $stripped = trim($content);
        if ($stripped === '') {
            return ['ok' => false, 'reason' => 'empty_content'];
        }

        // PeepSo's add_post is an instance method — fetch the singleton
        // via the same accessor PeepSo's UI uses.
        $activity = \PeepSoActivity::get_instance();

        $postId = $activity->add_post($authorId, $authorId, $stripped);

        if ($postId === false || (int) $postId <= 0) {
            return ['ok' => false, 'reason' => 'persist_failed'];
        }
        $postId = (int) $postId;

        $actId = self::resolveActIdForPost($postId);
        if ($actId <= 0) {
            // The wp_post landed but the activity row didn't appear.
            // PeepSo logs the underlying error itself; callers should
            // treat this as a soft failure (the post exists, but the
            // feed won't surface it). Surface the post_id so the
            // caller can decide whether to clean up.
            return ['ok' => false, 'reason' => 'persist_failed'];
        }

        return [
            'ok'      => true,
            'post_id' => $postId,
            'act_id'  => $actId,
        ];
    }

    /**
     * Create a §D6 blog post on $authorId's own wall.
     *
     * Differs from createSelfStatus in that it bypasses
     * `PeepSoActivity::add_post` (which always emits a status-kinded
     * peepso_activities row). Blog posts need module_id='blog' on the
     * activities row, so that side-effect would have to be reversed
     * after the fact — cleaner to write the wp_post directly here and
     * leave the activity-row insert to ActivityStreamWriter (which
     * subscribes to bcc_blog_post_created and routes through
     * PeepSoActivityWriter, the canonical write path for BCC-defined
     * activity kinds — same pattern reviews and pull batches use).
     *
     * Storage:
     *   - post_type    = peepso-activity-status (reused, no CPT registration)
     *   - post_status  = publish
     *   - post_excerpt = §D6 excerpt (300–500 chars; Floor renders this)
     *   - post_content = §D6 full_text (no cap; blog tab renders this)
     *   - post_author  = $authorId
     *
     * Returns the wp_post ID on success or 0 on failure. Caller
     * (PostsService) writes any sidecar metadata + fires the §A3 event.
     */
    public static function createSelfBlogPost(
        int $authorId,
        string $excerpt,
        string $fullText
    ): int {
        if ($authorId <= 0) {
            return 0;
        }

        $excerpt  = trim($excerpt);
        $fullText = trim($fullText);
        if ($excerpt === '' || $fullText === '') {
            return 0;
        }

        $postId = wp_insert_post([
            'post_type'    => 'peepso-activity-status',
            'post_status'  => 'publish',
            'post_author'  => $authorId,
            'post_title'   => '',
            // wp_kses_post on save protects against script injection in the
            // body. Markdown stays as-is; the frontend handles rendering.
            'post_excerpt' => wp_kses_post($excerpt),
            'post_content' => wp_kses_post($fullText),
        ], true);

        if (is_wp_error($postId) || (int) $postId <= 0) {
            return 0;
        }

        return (int) $postId;
    }

    /**
     * Resolve the act_id PeepSo just inserted by reading peepso_activities
     * back. add_post returns the wp_post ID, not the act_id, so we look
     * up the row by (act_external_id, act_module_id = PeepSoActivity::MODULE_ID).
     * LIMIT 1 — the (external_id, module_id) pair is effectively unique
     * for status posts.
     *
     * Why not match `'status'`: peepso_activities.act_module_id is a
     * numeric column. PeepSo writes the integer module id (1 for the
     * activity module), not the string 'status'. Filtering by string
     * always returns 0 rows. Matching by the canonical PeepSoActivity::MODULE_ID
     * constant is the correct lookup. (BCC-owned modules like
     * 'review'/'pull_batch' DO write strings — those are write paths
     * BCC owns, not PeepSo's native status path.)
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
