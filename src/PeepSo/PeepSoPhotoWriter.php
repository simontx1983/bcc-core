<?php
/**
 * PeepSoPhotoWriter — thin wrapper around PeepSo's photo write path.
 *
 * BCC must NOT INSERT directly into peepso_activities OR peepso_photos
 * for photo posts (single-graph rule, mirrors PeepSoStatusWriter /
 * PeepSoCommentWriter / PeepSoReactionWriter). PeepSo owns:
 *   - the wp_post (peepso-post CPT) creation
 *   - the peepso_activities insert (act_module_id stamped to 4 by the
 *     `peepso_activity_insert_data` filter when $_POST['type']==='photo')
 *   - image processing pipeline: EXIF orientation, resize, thumbnail
 *     variants ('s_s', 's_m', 's_l'), Imagick metadata strip, JPEG
 *     compression
 *   - peepso_photos row (pho_post_id + pho_owner_id + pho_album_id +
 *     pho_filesystem_name + pho_thumbs JSON)
 *   - Amazon S3 upload when configured (pho_token + pho_stored)
 *   - special-case GIF handling (animated .gif kept alongside the
 *     processed .jpg derivative)
 *   - notification fan-out + the `peepso_after_add_post` action hook
 *
 * Bypassing any of those would silently break content moderation,
 * S3 cost accounting, GIF playback, etc. So this writer drives PeepSo's
 * own flow rather than reimplementing it.
 *
 * The flow is two-step in PeepSo's UI (AJAX upload_photo → AJAX
 * add_post with type=photo + files=[hash]) and the integration
 * surface is filter-and-hook-based: `PeepSoSharePhotos::activity_insert_data`
 * stamps act_module_id when it sees `$_POST['type']==='photo'`, and
 * `PeepSoSharePhotos::after_add_post` reads `$_POST['files']` and calls
 * `PeepSoPhotosModel::save_images($files, $post_id, $act_id)`. So this
 * writer:
 *
 *   1. validates the file (mime via wp_check_filetype_and_ext, size cap)
 *   2. stages the file in PeepSo's per-user `tmp/` dir under a hash
 *      filename matching `upload_photo`'s convention
 *   3. for GIF: also drops the original .gif alongside the .jpg (mirrors
 *      upload_photo's GIF special-case at peepsosharephotos.php line 212)
 *   4. sets the `$_POST` superglobals PeepSo's filter+hook expect
 *   5. calls `PeepSoActivity::add_post(...)` — PeepSo handles wp_post
 *      insert, activity-row insert (stamped act_module_id=4), notifications,
 *      and via the after_add_post hook, save_images
 *   6. restores `$_POST` in a `try/finally` so request state never leaks
 *   7. resolves act_id + photo_id from the database for the response
 *
 * The `$_POST` manipulation is an unusual pattern in clean architecture
 * but it's the documented integration surface PeepSo's own AJAX layer
 * uses; reimplementing PeepSo's flow without it would mean copying the
 * filter logic, the S3 logic, the orientation logic, etc. — exactly the
 * "rebuild a working stack" trap §11 forbids.
 *
 * V1 scope: single photo per post, owner==author (self wall), local or
 * S3 storage (PeepSo handles both transparently). Multi-photo, group/page
 * walls, and album-targeted posts are V2+.
 *
 * @package BCC\Core\PeepSo
 * @since v1.5 (2026-05, Phase 1b photo composer)
 */

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoPhotoWriter
{
    /** Allowed mime types for photo uploads — keep in sync with the
     *  REST endpoint's enum. WebP is included for modern devices that
     *  default to it; GIF is included for the social-warmth use case. */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** Hard size cap. Mirrors MyProfileEndpoint::COVER_MAX_BYTES (5 MB).
     *  PeepSo's own `photos_max_upload_size` may be smaller; the smaller
     *  of the two wins inside the validator. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Create a photo post on $authorId's own wall.
     *
     * Return shape mirrors PeepSoStatusWriter::createSelfStatus with a
     * `photo_id` addition (peepso_photos.pho_id):
     *   - ['ok' => true,  'post_id' => int, 'act_id' => int, 'photo_id' => int]
     *   - ['ok' => false, 'reason' => 'forbidden']         authorId<=0
     *   - ['ok' => false, 'reason' => 'unavailable']       PeepSo deactivated / classes missing
     *   - ['ok' => false, 'reason' => 'upload_failed']     PHP UPLOAD_ERR_*
     *   - ['ok' => false, 'reason' => 'too_large']         exceeds MAX_BYTES
     *   - ['ok' => false, 'reason' => 'invalid_upload']    is_uploaded_file() false (suspicious path)
     *   - ['ok' => false, 'reason' => 'unsupported_mime']  not in ALLOWED_MIME_TYPES
     *   - ['ok' => false, 'reason' => 'tmp_unavailable']   tmp dir missing + un-creatable
     *   - ['ok' => false, 'reason' => 'persist_failed']    move_uploaded_file failed OR add_post returned 0/false OR resolve query came back empty
     *
     * `$file` is the loose `$_FILES`-shaped array WP_REST_Request hands
     * out — every value is `mixed` from PHPStan's perspective. The
     * writer narrows defensively (error code, tmp_name string check,
     * `is_uploaded_file()`, size cap, mime sniff via
     * `wp_check_filetype_and_ext`) so widening the parameter to
     * `array<string, mixed>` is honest about the runtime contract.
     *
     * Group-wall variant: when $groupId > 0 the caller has already
     * verified existence + viewer membership upstream (PostsService
     * via {@see \BCC\Trust\Core\Services\GroupsService::resolveGroupAccess}).
     * We keep $_POST['module_id'] = PeepSoSharePhotos::MODULE_ID (4)
     * so PeepSo's photos plugin still stamps act_module_id=4 (without
     * which the post wouldn't render as a photo card). After the post
     * is persisted we stamp `peepso_group_id` post-meta and fire
     * `peepso_groups_new_post` via {@see PeepSoStatusWriter::attachToGroup}
     * — same uniform group-attach path the status writer uses, no
     * conflict with the photos module_id slot.
     *
     * @param array<string, mixed> $file
     * @return array{ok: true, post_id: int, act_id: int, photo_id: int}|array{ok: false, reason: string}
     */
    public static function createSelfPhotoPost(int $authorId, array $file, string $caption, int $groupId = 0): array
    {
        if ($authorId <= 0) {
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        if (
            !class_exists('PeepSoActivity')
            || !class_exists('PeepSoPhotosModel')
            || !class_exists('PeepSoSharePhotos')
        ) {
            return ['ok' => false, 'reason' => 'unavailable'];
        }

        // ─── Validation ──────────────────────────────────────────────
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'reason' => 'upload_failed'];
        }

        $sourceTmp = (string) ($file['tmp_name'] ?? '');
        if ($sourceTmp === '' || !is_uploaded_file($sourceTmp)) {
            return ['ok' => false, 'reason' => 'invalid_upload'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'reason' => 'too_large'];
        }

        // Mime via wp_check_filetype_and_ext — does NOT trust the
        // browser-supplied Content-Type. Mirrors MyProfileEndpoint:514.
        $originalName = (string) ($file['name'] ?? 'upload');
        $checked = wp_check_filetype_and_ext($sourceTmp, $originalName);
        $mime = (string) ($checked['type'] ?? '');
        $ext  = (string) ($checked['ext']  ?? '');
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return ['ok' => false, 'reason' => 'unsupported_mime'];
        }
        $isGif = ($mime === 'image/gif');

        // ─── Stage in PeepSo's tmp dir ───────────────────────────────
        // PeepSo's save_images expects files in get_tmp_dir() under
        // a hash filename matching the convention upload_photo uses
        // (md5(name + time) . '.jpg'). For GIFs, both .jpg derivative
        // and original .gif must coexist (peepsosharephotos line 212).
        $photosModel = new \PeepSoPhotosModel();
        $tmpDir = $photosModel->get_tmp_dir();
        if (!is_dir($tmpDir)) {
            // get_tmp_dir derives from the user's photo dir; create the
            // chain ourselves if PeepSo hasn't seeded it yet for this user.
            if (!@mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
                return ['ok' => false, 'reason' => 'tmp_unavailable'];
            }
        }

        // Hash filename: PeepSo's convention is md5($name . time()).'.jpg'.
        // We use microtime for a tighter collision window. The .jpg
        // suffix is canonical even for non-jpg sources — save_images
        // converts everything to JPEG. GIFs keep their .gif sidecar.
        $hashName = md5($originalName . microtime(true)) . '.jpg';
        $tmpJpg   = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hashName;

        if (!@move_uploaded_file($sourceTmp, $tmpJpg)) {
            return ['ok' => false, 'reason' => 'persist_failed'];
        }

        if ($isGif) {
            // GIF special-case: keep the animated original alongside
            // the JPEG derivative. PeepSo's save_images sees both files
            // and stashes pho_thumbs['gif'] for animated playback.
            $tmpGif = self::replaceExtension($tmpJpg, 'gif');
            @copy($tmpJpg, $tmpGif);
        }
        unset($ext); // ext was for diagnostics; the .jpg suffix above is canonical

        // ─── Drive PeepSo's two-step write via $_POST ────────────────
        // The activity_insert_data filter and after_add_post hook both
        // read $_POST. We set the keys, call add_post, restore in
        // try/finally so a request never leaks photo state to a
        // later call within the same request lifecycle.
        //
        // We capture the pre-call state with `array_key_exists` so the
        // restore can faithfully distinguish "key was absent" from
        // "key was present with null" (rare in practice but the
        // distinction matters for any caller that explicitly nulled
        // a $_POST key for its own filter chain).
        $hadType    = array_key_exists('type', $_POST);
        $hadFiles   = array_key_exists('files', $_POST);
        $hadModule  = array_key_exists('module_id', $_POST);
        $prevType   = $hadType   ? $_POST['type']      : null;
        $prevFiles  = $hadFiles  ? $_POST['files']     : null;
        $prevModule = $hadModule ? $_POST['module_id'] : null;

        $_POST['type']      = 'photo';
        $_POST['files']     = [$hashName];
        $_POST['module_id'] = \PeepSoSharePhotos::MODULE_ID;

        try {
            // PeepSo's add_post may reject a strictly empty content
            // string. Photo-only posts are a real social use case, so
            // we pad with a single space when the caption is empty —
            // PeepSo accepts that and the rendered card filters whitespace
            // back out via trim() at display time.
            $captionTrimmed = trim($caption);
            $contentForPeepSo = $captionTrimmed === '' ? ' ' : $captionTrimmed;

            $postId = \PeepSoActivity::get_instance()->add_post(
                $authorId,
                $authorId,
                $contentForPeepSo
            );
        } finally {
            self::restorePostKey('type',      $hadType,   $prevType);
            self::restorePostKey('files',     $hadFiles,  $prevFiles);
            self::restorePostKey('module_id', $hadModule, $prevModule);
        }

        if ($postId === false || (int) $postId <= 0) {
            // Cleanup — staged tmp files would otherwise leak.
            @unlink($tmpJpg);
            if ($isGif) {
                @unlink(self::replaceExtension($tmpJpg, 'gif'));
            }
            return ['ok' => false, 'reason' => 'persist_failed'];
        }
        $postId = (int) $postId;

        // ─── Resolve act_id + photo_id ───────────────────────────────
        // PeepSo's activity_insert_data filter stamped act_module_id=4
        // on the row created during add_post. Look up by
        // (act_external_id=postId, act_module_id=4). save_images
        // ALSO inserts a SECOND activity row (act_external_id=pho_id,
        // act_module_id=4) — but that row's INNER JOIN to wp_posts
        // fails because pho_id is not a wp_post.ID, so the feed never
        // surfaces it. We pick the post-row via the postId match.
        $actId   = self::resolveActIdForPost($postId);
        $photoId = self::resolvePhotoIdForPost($postId);

        if ($actId <= 0) {
            return ['ok' => false, 'reason' => 'persist_failed'];
        }

        if ($groupId > 0) {
            // Single source of truth for "stamp group-meta + fire
            // peepso_groups_new_post." Lives on PeepSoStatusWriter;
            // this writer reuses it (per §11) rather than duplicating
            // the post-meta + action-fire pair.
            PeepSoStatusWriter::attachToGroup($postId, $groupId);
        }

        return [
            'ok'       => true,
            'post_id'  => $postId,
            'act_id'   => $actId,
            'photo_id' => $photoId,
        ];
    }

    /**
     * Reset a $_POST key to its pre-call state. `$wasPresent` is the
     * `array_key_exists` boolean captured before the call — without
     * it, "absent" and "present-with-null" are indistinguishable from
     * a `null === ?` check, which would silently unset a key that the
     * caller had explicitly null'd.
     */
    private static function restorePostKey(string $key, bool $wasPresent, mixed $previous): void
    {
        if (!$wasPresent) {
            unset($_POST[$key]);
            return;
        }
        $_POST[$key] = $previous;
    }

    private static function replaceExtension(string $path, string $newExt): string
    {
        $info = pathinfo($path);
        $dir  = isset($info['dirname']) && $info['dirname'] !== '' ? $info['dirname'] . DIRECTORY_SEPARATOR : '';
        $name = (string) ($info['filename'] ?? '');
        return $dir . $name . '.' . $newExt;
    }

    /**
     * Activity row pointing at the photo post's wp_post.ID. Filtered
     * by act_module_id = PeepSoSharePhotos::MODULE_ID (4) so the
     * second activity row save_images writes (which points at
     * peepso_photos.id) is not confused for the post row.
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
            \PeepSoSharePhotos::MODULE_ID
        ));
    }

    /**
     * peepso_photos.pho_id for a given post. Returned in the success
     * response so callers can include it in BCC's §A3
     * `bcc_post_created` event payload (subscribers may want it for
     * notification body lookups).
     */
    private static function resolvePhotoIdForPost(int $postId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_photos';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT pho_id FROM {$table}
              WHERE pho_post_id = %d
              LIMIT 1",
            $postId
        ));
    }
}
