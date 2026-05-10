<?php
/**
 * PeepSoCommentWriter — thin wrapper around PeepSoActivity::add_comment
 * for create + WP's wp_delete_post for remove.
 *
 * BCC must NOT INSERT directly into peepso_activities for comments
 * (single-graph rule, mirrors PeepSoReactionWriter / PeepSoFollowWriter).
 * PeepSo's add_comment owns:
 *   - content sanitization (htmlspecialchars + strip_content + the
 *     site_status_limit char cap)
 *   - permission check on the parent post owner (PeepSo::check_permissions
 *     with PERM_COMMENT)
 *   - the `peepso_disable_comments` post-meta gate on the parent
 *   - notification fan-out to author + post followers
 *   - the `peepso_after_add_comment` action hook other plugins listen to
 *
 * Bypassing add_comment would silently break all of those concerns,
 * so this writer is the only allowed write path from BCC.
 *
 * Contract:
 *   - addComment(int $parentPostId, int $authorId, string $content, int $parentModuleId): int
 *       Creates a comment on the parent wp_post. `$parentPostId` is
 *       the parent activity's `act_external_id` (the parent's
 *       wp_posts.ID), NOT its act_id. `$parentModuleId` is the
 *       parent activity's `act_module_id` — the SMALLINT PeepSo uses
 *       to disambiguate which module owns the post (1 for status,
 *       4 for photos, 30 for polls, 6661 for blog posts, etc.).
 *       PeepSo's `add_comment` needs this to find the parent
 *       activity row via `get_activity_data($postId, $moduleId)`;
 *       defaulting to MODULE_ID=1 (status) silently fails on every
 *       other kind. Returns the new comment's wp_posts.ID on success,
 *       0 on failure.
 *
 *   - deleteComment(int $commentPostId): bool
 *       Soft-deletes the comment by trashing the wp_post (post_status
 *       transitions to 'trash'). PeepSo's read paths filter on
 *       post_status='publish' so trashed comments disappear from
 *       feeds without breaking referential integrity.
 *
 *       Caller is responsible for authorization — this method does
 *       NOT check whether $viewerId owns the comment. Use
 *       CommentRepository::getCommentAuthorId() before calling.
 *
 * Returns false / 0 on:
 *   - PeepSoActivity class missing (PeepSo deactivated)
 *   - invalid IDs (zero or negative)
 *   - PeepSoActivity::add_comment refusal (parent comments disabled,
 *     parent owner blocked the commenter, content empty after strip)
 *
 * @package BCC\Core\PeepSo
 * @since v1.5 (2026-05, hybrid PeepSo-proxy comments)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoCommentWriter
{
    /**
     * Create a comment on the given parent post.
     *
     * @param int    $parentPostId    Parent activity's act_external_id (wp_posts.ID).
     * @param int    $authorId        Commenter's user ID.
     * @param string $content         Raw comment body — PeepSo will sanitize.
     * @param int    $parentModuleId  Parent activity's act_module_id (SMALLINT).
     *                                MUST be the value stored on the parent's
     *                                peepso_activities row, not a guess —
     *                                PeepSo looks up the parent via
     *                                (act_external_id, act_module_id) and
     *                                returns FALSE if the pair doesn't
     *                                resolve.
     * @return int wp_posts.ID of the new comment, 0 on failure.
     */
    public static function addComment(
        int $parentPostId,
        int $authorId,
        string $content,
        int $parentModuleId
    ): int {
        if ($parentPostId <= 0 || $authorId <= 0 || $parentModuleId <= 0 || trim($content) === '') {
            return 0;
        }
        if (!class_exists('PeepSoActivity')) {
            \BCC\Core\Observability\DegradationMetrics::record('peepso_absence', 'comment_writer_add');
            static $loggedOnce = false;
            if (!$loggedOnce) {
                \BCC\Core\Log\Logger::warning('[bcc-core] PeepSo not loaded — degraded path in ' . __METHOD__);
                $loggedOnce = true;
            }
            return 0;
        }

        // PeepSoActivity is a singleton with a get_instance() pattern.
        // The method returns the new comment's post.ID on success, or
        // FALSE on permission/disable/empty failures (see activity.php
        // line 361 — it doesn't throw).
        //
        // `module_id` in $extra makes PeepSo's add_comment look up the
        // parent via the right (post_id, module_id) pair. Without it,
        // PeepSo defaults to PeepSoActivity::MODULE_ID = 1 (status),
        // which fails for every other kind (photo=4, poll=30, blog=6661,
        // etc.).
        $result = \PeepSoActivity::get_instance()->add_comment(
            $parentPostId,
            $authorId,
            $content,
            ['module_id' => $parentModuleId]
        );

        if ($result === false || !is_int($result)) {
            return 0;
        }
        return $result > 0 ? $result : 0;
    }

    /**
     * Soft-delete a comment by trashing its wp_post. PeepSo's read
     * paths (and BCC's CommentRepository) filter on post_status =
     * 'publish' so the comment disappears from view immediately.
     *
     * Trashing rather than wp_delete_post(force=true) preserves the
     * peepso_activities row + any moderation/audit history. PeepSo's
     * own delete_post() walks the activity row and removes both, but
     * also deletes nested replies + likes — heavy-handed for a
     * "viewer removed their own comment" path.
     */
    public static function deleteComment(int $commentPostId): bool
    {
        if ($commentPostId <= 0) {
            return false;
        }

        $trashed = wp_trash_post($commentPostId);
        return $trashed !== false && $trashed !== null;
    }
}
