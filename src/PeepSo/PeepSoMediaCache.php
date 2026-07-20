<?php
/**
 * PeepSoMediaCache — single cached seam for PeepSo-resolved user media
 * URLs (avatar + cover photo). Lives in bcc-core because PeepSo
 * integration is bcc-core's domain (alongside PeepSoGroupWriter /
 * PeepSoGroupRepository), so every consumer — the bcc-core activity feed
 * AND the bcc-trust card / profile view-models — resolves through one
 * cache rather than parallel implementations (§11).
 *
 * Both URLs are resolved via PeepSo: avatar through
 * `PeepSoUser::get_instance($id)->get_avatar('full')` and cover through
 * `->has_cover()` + `->get_cover()`. PeepSo stores these under its own
 * image dir, so WP's native pipeline only sees them when PeepSo is
 * filtering it (a plugin option); asking PeepSo directly is the reliable
 * resolution. NB: WP's `get_avatar_url()` already routes through PeepSo's
 * `get_avatar_url` filter, which itself constructs a PeepSoUser and calls
 * `get_avatar('full')` — so callers that used `get_avatar_url()` get an
 * identical URL from `avatarUrl()`, now cached.
 *
 * WHY CACHE (and why it's safe — unlike membership, see
 * PeepSoGroupRepository::getUserMemberGroupIds): resolving either URL
 * constructs a PeepSoUser, which runs a raw `SELECT * FROM peepso_users`
 * PeepSo issues directly (consulting no WP cache) plus get_user_meta and
 * a `file_exists()` stat — and get_avatar() even lazily WRITES
 * `usr_avatar_custom` — a per-user cost across every member list and feed
 * page. Caching the resolved URLs removes that on warm cache. Staleness is
 * COSMETIC: a stale URL 404s (the frontend falls back to the initials
 * monogram / default cover) or briefly shows the prior image. No
 * authorization or content-visibility decision rides on these values, so a
 * missed bust cannot leak.
 *
 * Invalidation (wired in bcc-core.php on added/updated/deleted user-meta):
 * PeepSo writes `peepso_avatar_hash` / `peepso_cover_hash` via
 * update_user_meta on change, and `peepso_use_gravatar` toggles the avatar
 * branch — so a write to any of those keys busts that user's cached URLs.
 * A short TTL backstops any path that bypasses user-meta.
 *
 * Degrades cleanly without a persistent object cache: with no Redis
 * drop-in `wp_cache_*` is request-scoped, so this still collapses repeat
 * resolutions within a request and simply doesn't persist across them.
 *
 * @package BCC\Core\PeepSo
 * @since 2026-06-18 (perf audit P1-B / P1-C; relocated from bcc-trust)
 */

declare(strict_types=1);

namespace BCC\Core\PeepSo;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoMediaCache
{
    private const CACHE_GROUP   = 'bcc_core:user_media';
    private const AVATAR_PREFIX = 'avatar:';
    private const COVER_PREFIX  = 'cover:';
    /** Backstop for any change path that bypasses the user-meta bust. */
    private const CACHE_TTL = 3600; // 1h

    /** User-meta keys whose change alters a resolved media URL. */
    private const BUST_META_KEYS = [
        'peepso_avatar_hash',
        'peepso_use_gravatar',
        'peepso_cover_hash',
    ];

    /**
     * Resolved avatar URL for $userId, or '' when none resolves (the
     * frontend renders its initials monogram on empty).
     */
    public static function avatarUrl(int $userId): string
    {
        if ($userId <= 0) {
            // Anon/invalid — no stable identity to key on; resolve uncached.
            $url = get_avatar_url($userId);
            return is_string($url) ? $url : '';
        }

        $key    = self::AVATAR_PREFIX . $userId;
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        // '' is a valid cached value ("no custom avatar"); only `false`
        // (miss) falls through to recompute.
        if (is_string($cached)) {
            return $cached;
        }

        $url = self::computeAvatar($userId);
        wp_cache_set($key, $url, self::CACHE_GROUP, self::CACHE_TTL);
        return $url;
    }

    /**
     * Bulk avatar resolution — one wp_cache_get_multiple round trip for
     * every cached entry, per-user compute only for the misses. With a
     * persistent object cache (LSMCD/memcached/Redis) this collapses N
     * network round trips per feed/member page into one; without one,
     * wp_cache_get_multiple degrades to the same request-local lookups
     * avatarUrl() would have done. Cold entries still pay PeepSo's
     * per-user resolution — that cost is inherent to PeepSo's API. No
     * stampede guard (deliberate): a stale/missing avatar is cosmetic
     * and each compute is one user's row, unlike get_page_owner.
     *
     * @param list<int> $userIds
     * @return array<int, string> userId => resolved URL ('' = none; render
     *                            the initials monogram). Invalid ids (<= 0)
     *                            are omitted.
     */
    public static function avatarUrlBulk(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $keys = array_map(
            static fn(int $id): string => self::AVATAR_PREFIX . $id,
            $ids
        );
        /** @var array<string, mixed> $cached */
        $cached = wp_cache_get_multiple($keys, self::CACHE_GROUP);

        $out = [];
        foreach ($ids as $id) {
            $hit = $cached[self::AVATAR_PREFIX . $id] ?? false;
            // '' is a valid cached value ("no custom avatar"); only a
            // non-string (miss) falls through to recompute — same
            // contract as avatarUrl().
            if (is_string($hit)) {
                $out[$id] = $hit;
                continue;
            }
            $url = self::computeAvatar($id);
            wp_cache_set(self::AVATAR_PREFIX . $id, $url, self::CACHE_GROUP, self::CACHE_TTL);
            $out[$id] = $url;
        }
        return $out;
    }

    /**
     * Resolved cover-photo URL for $userId, or null when the user has no
     * custom cover (frontend falls back to a default treatment, §1.7).
     */
    public static function coverPhotoUrl(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $key    = self::COVER_PREFIX . $userId;
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        // Cached '' encodes "no cover" (→ null); a non-empty string is the
        // URL; only `false` (miss) recomputes.
        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $url = self::computeCover($userId);
        wp_cache_set($key, $url ?? '', self::CACHE_GROUP, self::CACHE_TTL);
        return $url;
    }

    /**
     * Drop one user's cached media URLs. Public so the user-meta bust
     * closures in bcc-core.php can call it; safe on a cold cache. Busts
     * both keys regardless of which meta changed — over-busting is a cheap
     * recompute, and it keeps the wiring from having to map key→asset.
     */
    public static function bust(int $userId): void
    {
        if ($userId > 0) {
            wp_cache_delete(self::AVATAR_PREFIX . $userId, self::CACHE_GROUP);
            wp_cache_delete(self::COVER_PREFIX . $userId, self::CACHE_GROUP);
        }
    }

    /**
     * Whether $metaKey is one whose change should bust the media cache.
     * Keeps the meta-key list owned by this class rather than the wiring.
     */
    public static function isBustMetaKey(string $metaKey): bool
    {
        return in_array($metaKey, self::BUST_META_KEYS, true);
    }

    /** PeepSo-first avatar resolution, WP native fallback. */
    private static function computeAvatar(int $userId): string
    {
        if (class_exists('\\PeepSoUser')) {
            $peepso = \PeepSoUser::get_instance($userId);
            $url    = $peepso->get_avatar('full');
            if ($url !== '') {
                return $url;
            }
        }
        $url = get_avatar_url($userId);
        return is_string($url) ? $url : '';
    }

    /** PeepSo cover resolution; null when PeepSo absent or no custom cover. */
    private static function computeCover(int $userId): ?string
    {
        if (!class_exists('\\PeepSoUser')) {
            return null;
        }
        $instance = \PeepSoUser::get_instance($userId);
        if (!$instance->has_cover()) {
            return null;
        }
        $url = $instance->get_cover();
        return $url !== '' ? $url : null;
    }
}
