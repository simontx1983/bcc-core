<?php
/**
 * UserMetaRepository — wp_usermeta reads that tell "no row" from "the read
 * did not run".
 *
 * The one guarded user-meta reader for BCC (§11). Use it for any value
 * that DECIDES something (a privacy flag, a messaging preference, an
 * opt-out) when its absence means a default: read through ProbesReads,
 * never get_user_meta(); a read that cannot be trusted answers null, and
 * callers fail closed.
 *
 * Here the values come from bounded queries through ProbesReads (the read
 * itself is {@see ReadsMetaRows}, shared with {@see PostMetaRepository}):
 *   - clearLastError(), then query() compared `=== false`;
 *   - then last_error, then the rows (trustedRows());
 *   - never get_results()/get_col() plus last_error, which can hand back
 *     the PREVIOUS query's rows when query() bails before flush().
 * A read that cannot be trusted answers null, and the caller picks its
 * closed default. A genuine "no row" is an absent key.
 *
 * Shape: user id => meta key => value(s). A key with no row is absent, and
 * a user with no row for any requested key has no entry. Values are the RAW
 * meta_value strings, not unserialised: the caller knows what each key
 * holds, and a compare-and-swap needs the exact stored string.
 *
 * Bounds: at most MAX_KEYS keys and BATCH_MAX users per call (more is
 * refused with null, never truncated), read CHUNK_USERS users per query.
 * A chunk whose rows fill its LIMIT, or any chunk that fails, makes the
 * whole answer null.
 *
 * Memo: trusted answers are kept for the request, so a page that renders
 * one member many times reads them once, and a list can batch the read up
 * front. Any write to a member's meta drops that member's memo. Pass
 * `$fresh = true` to neither read nor write the memo: a compare-and-swap
 * retry loop, or a value that must never be memoised.
 * ⚠ Cron and other long-running consumers, and every compare-and-swap,
 * pass `$fresh = true`: the memo cannot see writes made by other
 * processes.
 * ⚠ Pass single-valued keys. The LIMIT is an average over the whole chunk
 * (MAX_ROWS_PER_PAIR rows per pair), so a key that legitimately holds many
 * rows per user would push a chunk over it and read as null.
 *
 * @package BCC\Core\Repositories
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class UserMetaRepository
{
    use ProbesReads;
    use ReadsMetaRows;

    /** Users per query. A larger call is read in chunks. */
    public const CHUNK_USERS = 100;

    /** Users per call at most. A larger call is refused, never truncated. */
    public const BATCH_MAX = 1000;

    /** Keys per call at most. */
    public const MAX_KEYS = 10;

    /**
     * Rows allowed per (user, key) pair on average. Pairs normally have one;
     * WordPress allows duplicates. A chunk that reaches its LIMIT is refused:
     * ORDER BY umeta_id cuts the NEWEST rows, which can include some pair's
     * only row.
     */
    private const MAX_ROWS_PER_PAIR = 5;

    /** The memo is dropped wholesale past this many users (long cron runs). */
    private const MEMO_MAX = 5000;

    /**
     * user => key => values, trusted reads only. A key read with no row
     * holds [], so a repeat costs nothing.
     *
     * @var array<int, array<string, list<string>>>
     */
    private static array $memo = [];

    private static bool $listening = false;

    /**
     * Every stored value per (user, key), lowest umeta_id first, or null
     * when the read cannot be trusted.
     *
     * @param list<int>    $userIds
     * @param list<string> $keys
     * @return array<int, array<string, list<string>>>|null
     */
    public static function valuesOrUnknown(array $userIds, array $keys, bool $fresh = false): ?array
    {
        $ids  = self::cleanIds($userIds);
        $keys = self::cleanKeys($keys);
        if ($ids === [] || $keys === []) {
            return [];
        }
        if (count($keys) > self::MAX_KEYS || count($ids) > self::BATCH_MAX) {
            return null;
        }

        if ($fresh) {
            $read = self::read($ids, $keys);

            return $read === null ? null : self::present($read, $ids, $keys);
        }

        // Make room BEFORE deciding what to read. A reset after that point
        // would drop users this call meant to serve from the memo, and
        // present() would answer "no row" for them.
        if (count(self::$memo) + count($ids) > self::MEMO_MAX) {
            self::$memo = [];
        }

        $toRead = [];
        foreach ($ids as $id) {
            foreach ($keys as $key) {
                if (!isset(self::$memo[$id]) || !array_key_exists($key, self::$memo[$id])) {
                    $toRead[] = $id;
                    break;
                }
            }
        }

        if ($toRead !== []) {
            $read = self::read($toRead, $keys);
            if ($read === null) {
                return null;
            }
            self::listen();
            foreach ($read as $id => $values) {
                // array_replace, not array_merge: a numeric meta key must keep its key.
                self::$memo[$id] = array_replace(self::$memo[$id] ?? [], $values);
            }
        }

        return self::present(self::$memo, $ids, $keys);
    }

    /**
     * The FIRST stored value per (user, key), lowest umeta_id: what
     * get_user_meta(…, true) answers when a key is duplicated. Same
     * absent-means-no-row shape and null rule as {@see valuesOrUnknown()}.
     *
     * @param list<int>    $userIds
     * @param list<string> $keys
     * @return array<int, array<string, string>>|null
     */
    public static function firstValuesOrUnknown(array $userIds, array $keys, bool $fresh = false): ?array
    {
        $all = self::valuesOrUnknown($userIds, $keys, $fresh);
        if ($all === null) {
            return null;
        }

        $out = [];
        foreach ($all as $id => $byKey) {
            foreach ($byKey as $key => $values) {
                $out[$id][$key] = $values[0];
            }
        }

        return $out;
    }

    /** Drop one user's memo. Also wired to every user-meta write. */
    public static function forget(int $userId): void
    {
        unset(self::$memo[$userId]);
    }

    /**
     * The read, chunked. Every requested user maps every key, [] for no row.
     *
     * @param list<int>    $ids
     * @param list<string> $keys
     * @return array<int, array<string, list<string>>>|null
     */
    private static function read(array $ids, array $keys): ?array
    {
        global $wpdb;

        return self::readMetaRows($wpdb->usermeta, 'user_id', 'umeta_id', $ids, $keys, self::CHUNK_USERS, self::MAX_ROWS_PER_PAIR);
    }

    /**
     * Drop a user's memo on any write to their meta, so a later read in the
     * same request (a PATCH's echo, a migration) sees the new value. Hooked
     * on the first memo write; skipped where WordPress hooks don't exist.
     */
    private static function listen(): void
    {
        if (self::$listening || !function_exists('add_action')) {
            return;
        }
        self::$listening = true;

        $forget = static function ($metaId, $userId): void {
            unset($metaId);
            $id = is_numeric($userId) ? (int) $userId : 0;
            if ($id > 0) {
                self::forget($id);

                return;
            }
            // delete_metadata(..., $delete_all = true) fires with object id 0:
            // that key changed for every user, so no memoised answer stands.
            self::$memo = [];
        };
        foreach (['added_user_meta', 'updated_user_meta', 'deleted_user_meta'] as $hook) {
            add_action($hook, $forget, 10, 2);
        }
    }
}
