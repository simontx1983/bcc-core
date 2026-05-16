<?php

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to PeepSo photo albums (`peepso_photos_album`).
 *
 * Schema (per peepso-photos/install/activate.php, verified 2026-05-14):
 *   - pho_album_id      PK
 *   - pho_owner_id      owner user_id
 *   - pho_post_id       linked wp_posts row (the "album post")
 *   - pho_album_acc     PeepSo access enum (0=public, 1=members,
 *                       2=private, friends via PeepSoFriendsPlugin)
 *   - pho_album_name    title
 *   - pho_album_desc    description
 *   - pho_created       TIMESTAMP
 *   - pho_system_album  1 = auto-created album (Stream, Profile,
 *                       Cover), 0 = user-created
 *   - pho_module_id     module owner (0 = user profile)
 *   - pho_cover         pho_id of the cover photo in `peepso_photos`
 *
 * Cover URL resolution: the cover photo's `pho_filesystem_name` lives
 * in `peepso_photos`; the actual file is stored under
 * `{wp-content-uploads}/peepso/users/{owner_id}/photos/{filesystem_name}`.
 * We JOIN both tables and let the caller construct the URL using
 * `wp_upload_dir()` + the canonical PeepSo path.
 *
 * Privacy: this repo does NOT enforce viewer-scoped access — the
 * service layer applies the access rules (mirrors PeepSo's own
 * `get_user_photos_album` filter logic) so a single query can power
 * both owner and visitor surfaces with the same row set, filtered
 * server-side.
 *
 * No SELECT *. No writes.
 *
 * @phpstan-type AlbumRow object{
 *   pho_album_id: int|numeric-string,
 *   pho_owner_id: int|numeric-string,
 *   pho_post_id: int|numeric-string,
 *   pho_album_acc: int|numeric-string,
 *   pho_album_name: string,
 *   pho_album_desc: string|null,
 *   pho_created: string,
 *   pho_system_album: int|numeric-string,
 *   pho_module_id: int|numeric-string,
 *   pho_cover: int|numeric-string,
 *   cover_filesystem_name: string|null,
 *   cover_stored: int|numeric-string|null,
 *   cover_token: string|null,
 *   photo_count: int|numeric-string
 * }
 */
final class PeepSoAlbumRepository
{
    private const ALBUM_TABLE_SUFFIX = 'peepso_photos_album';
    private const PHOTOS_TABLE_SUFFIX = 'peepso_photos';

    /**
     * Hard cap on result-set size for the user-albums query. Profiles
     * rarely have more than ~20 albums in practice; 100 is the
     * defensive ceiling.
     */
    private const MAX_ALBUMS_PER_USER = 100;

    private static function albumTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::ALBUM_TABLE_SUFFIX;
    }

    private static function photosTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::PHOTOS_TABLE_SUFFIX;
    }

    /**
     * Return all albums owned by a user, newest first.
     *
     * Each row carries:
     *   - core album fields (id, title, desc, access, created)
     *   - cover_filesystem_name — joined from `peepso_photos` on
     *     `pho_cover`; null when the cover photo has been deleted or
     *     the album has no cover set
     *   - photo_count — COUNT of `peepso_photos` rows where
     *     `pho_album_id = pho_album_id` (correlated subquery)
     *
     * @param int $userId
     * @param int $limit  Capped at MAX_ALBUMS_PER_USER.
     * @return list<object>
     */
    public static function getAlbumsByOwner(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(self::MAX_ALBUMS_PER_USER, $limit));

        global $wpdb;

        $album    = self::albumTable();
        $photos   = self::photosTable();
        $photosE  = esc_sql($photos);
        $albumE   = esc_sql($album);

        // Photo count via a correlated subquery — cheap given the
        // owner+module-bounded result set + the pho_album_id index on
        // peepso_photos. Cover join is LEFT so albums without a
        // resolvable cover still surface (with cover_filesystem_name
        // null).
        $sql = $wpdb->prepare(
            "SELECT
                a.pho_album_id,
                a.pho_owner_id,
                a.pho_post_id,
                a.pho_album_acc,
                a.pho_album_name,
                a.pho_album_desc,
                a.pho_created,
                a.pho_system_album,
                a.pho_module_id,
                a.pho_cover,
                c.pho_filesystem_name AS cover_filesystem_name,
                c.pho_stored          AS cover_stored,
                c.pho_token           AS cover_token,
                (SELECT COUNT(*) FROM `{$photosE}` p
                   WHERE p.pho_album_id = a.pho_album_id) AS photo_count
              FROM `{$albumE}` a
              LEFT JOIN `{$photosE}` c ON c.pho_id = a.pho_cover
              WHERE a.pho_owner_id = %d
                AND a.pho_module_id = 0
              ORDER BY a.pho_system_album DESC, a.pho_created DESC
              LIMIT %d",
            $userId,
            $limit
        );

        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results($sql);

        return $rows === null ? [] : $rows;
    }

    /**
     * Single-album lookup used by the per-album endpoint for the
     * privacy gate. Bounded by PK + owner so an attacker can't probe
     * for another user's album IDs.
     *
     * Returns only the columns the privacy filter needs — no cover
     * JOIN, no correlated subquery; one indexed row read.
     *
     * The SELECT projects six columns — pho_album_id, pho_owner_id,
     * pho_album_acc, pho_album_name, pho_system_album, pho_module_id.
     * Callers duck-type property access (e.g. `$row->pho_album_acc ?? 0`)
     * rather than relying on a static phpstan-return shape — PHPStan
     * cannot infer the SELECT projection from `wpdb::get_row()` and
     * inline type overrides are forbidden by the bcc-trust §6 rule.
     */
    public static function findOneByIdAndOwner(int $albumId, int $ownerId): ?object
    {
        if ($albumId <= 0 || $ownerId <= 0) {
            return null;
        }

        global $wpdb;
        $album = self::albumTable();

        $sql = $wpdb->prepare(
            "SELECT
                pho_album_id,
                pho_owner_id,
                pho_album_acc,
                pho_album_name,
                pho_system_album,
                pho_module_id
              FROM `{$album}`
              WHERE pho_album_id = %d
                AND pho_owner_id = %d
                AND pho_module_id = 0
              LIMIT 1",
            $albumId,
            $ownerId
        );

        $row = $wpdb->get_row($sql);
        return $row instanceof \stdClass ? $row : null;
    }
}
