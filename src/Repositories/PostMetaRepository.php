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
}
