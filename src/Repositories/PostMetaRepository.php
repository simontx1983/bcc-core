<?php
/**
 * PostMetaRepository — wp_postmeta reads that tell "no row" from "the read
 * did not run". The post twin of {@see UserMetaRepository} (§11), on the
 * same shared read ({@see ReadsMetaRows}).
 *
 * Use it for any post-meta value that DECIDES something (a gate, a group's
 * kind, a threshold) when its absence means a default: read through
 * ProbesReads, never get_post_meta(); a read that cannot be trusted answers
 * null, and callers fail closed. A genuine "no row" is an absent key.
 *
 * Shape: post id => meta key => EVERY stored value, lowest meta_id first.
 * A key with no row is absent, and a post with no row for any requested key
 * has no entry. Values are the RAW meta_value strings, not unserialised. The
 * caller decides what duplicate rows mean for its key (the strictest, or
 * "disagreeing rows are unknown"); this class never picks one.
 *
 * Bounds: at most MAX_KEYS keys and BATCH_MAX posts per call (more is refused
 * with null, never truncated), read CHUNK_POSTS posts per query. A chunk
 * whose rows fill its LIMIT, or any chunk that fails, makes the whole answer
 * null. ⚠ Pass single-valued keys: the LIMIT is an average over the chunk.
 *
 * One write: {@see replaceWithOneRow()}, for a key that must hold exactly
 * one row (no read before it, insert before delete, probed).
 *
 * ⚠ NO MEMO, deliberately. Every call reads the table, so a decision never
 * rests on an answer from earlier in the request. If one is ever added, make
 * room BEFORE deciding which ids to read, as UserMetaRepository does: a reset
 * after that point drops ids the call meant to serve from the memo, and they
 * read as "no row".
 *
 * @package BCC\Core\Repositories
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PostMetaRepository
{
    use ProbesReads;
    use ReadsMetaRows;

    /** Posts per query. A larger call is read in chunks. */
    public const CHUNK_POSTS = 100;

    /** Posts per call at most. A larger call is refused, never truncated. */
    public const BATCH_MAX = 1000;

    /** Keys per call at most. */
    public const MAX_KEYS = 10;

    /** Rows allowed per (post, key) pair on average; see ReadsMetaRows. */
    private const MAX_ROWS_PER_PAIR = 5;

    /**
     * Every stored value per (post, key), lowest meta_id first, or null when
     * the read cannot be trusted.
     *
     * @param list<int>    $postIds
     * @param list<string> $keys
     * @return array<int, array<string, list<string>>>|null
     */
    public static function valuesOrUnknown(array $postIds, array $keys): ?array
    {
        $ids  = self::cleanIds($postIds);
        $keys = self::cleanKeys($keys);
        if ($ids === [] || $keys === []) {
            return [];
        }
        if (count($keys) > self::MAX_KEYS || count($ids) > self::BATCH_MAX) {
            return null;
        }

        global $wpdb;
        $read = self::readMetaRows($wpdb->postmeta, 'post_id', 'meta_id', $ids, $keys, self::CHUNK_POSTS, self::MAX_ROWS_PER_PAIR);

        return $read === null ? null : self::present($read, $ids, $keys);
    }

    /**
     * Leave EXACTLY ONE row of $key on the post, holding $value: insert it,
     * then delete that key's other rows. For a key that must be
     * single-valued (a group-facts key), in place of update_post_meta().
     *
     * - No read first. update_post_meta() decides between "update" and
     *   "add" from a read, so a read that fails as empty adds a second row;
     *   this never reads, so it cannot append on a failed read.
     * - Insert BEFORE delete. There is never a moment with no row, and a
     *   failure leaves at worst the old rows beside the new one, which
     *   every reader of these keys folds strictest-wins. Inside a caller's
     *   transaction, the caller's rollback undoes both.
     * - Probed: each statement must succeed (no `false`, no `last_error`),
     *   and the insert must add one row with an id.
     * - The post's meta cache is deleted once anything was written, so a
     *   later get_post_meta() in the same request reads the table.
     *
     * @return bool true when the post now has exactly this one row
     */
    public static function replaceWithOneRow(int $postId, string $key, string $value): bool
    {
        if ($postId <= 0 || $key === '' || strlen($key) > 255) {
            return false;
        }

        global $wpdb;

        self::clearLastError();
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            $postId,
            $key,
            $value
        ));
        $newId = (int) $wpdb->insert_id;
        if ($inserted !== 1 || self::lastReadFailed() || $newId <= 0) {
            if ($inserted !== false && (int) $inserted > 0) {
                wp_cache_delete($postId, 'post_meta');
            }
            return false;
        }

        self::clearLastError();
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_id <> %d",
            $postId,
            $key,
            $newId
        ));
        wp_cache_delete($postId, 'post_meta');

        return $deleted !== false && !self::lastReadFailed();
    }
}
