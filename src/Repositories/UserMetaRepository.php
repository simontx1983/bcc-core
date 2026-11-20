<?php
/**
 * UserMetaRepository — wp_usermeta reads that tell "no row" from "the read
 * did not run".
 *
 * The one guarded user-meta reader for BCC (§11). Use it for any value
 * that DECIDES something (a privacy flag, a messaging preference, an
 * opt-out) when its absence means a default. get_user_meta() reads
 * through WordPress's meta cache, and the cache layer does not tell the
 * caller when its own load failed, so the answer cannot be trusted for
 * such a decision.
 *
 * Here the values come from bounded queries through ProbesReads:
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
            if (count(self::$memo) + count($read) > self::MEMO_MAX) {
                self::$memo = [];
            }
            foreach ($read as $id => $values) {
                self::$memo[$id] = array_merge(self::$memo[$id] ?? [], $values);
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

        $out = [];
        foreach (array_chunk($ids, self::CHUNK_USERS) as $chunk) {
            $limit = count($chunk) * count($keys) * self::MAX_ROWS_PER_PAIR;
            $sql   = $wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
                  WHERE user_id IN (" . implode(', ', array_fill(0, count($chunk), '%d')) . ")
                    AND meta_key IN (" . implode(', ', array_fill(0, count($keys), '%s')) . ")
                  ORDER BY umeta_id ASC
                  LIMIT %d",
                ...[...$chunk, ...$keys, $limit]
            );
            if (!is_string($sql) || $sql === '') {
                return null;
            }

            $rows = self::trustedRows($sql);
            if ($rows === null || count($rows) >= $limit) {
                return null;
            }

            $asked = [];
            foreach ($chunk as $id) {
                $asked[$id]  = true;
                $out[$id]    = array_fill_keys($keys, []);
            }
            foreach ($rows as $row) {
                $id  = isset($row->user_id) && is_numeric($row->user_id) ? (int) $row->user_id : 0;
                $key = isset($row->meta_key) ? (string) $row->meta_key : '';
                if (!isset($asked[$id])) {
                    // A user this query did not ask for: the rows are not ours.
                    return null;
                }
                if (!array_key_exists($key, $out[$id])) {
                    // A case variant matched by a _ci collation. WordPress reads
                    // meta keys exactly, so this skips it as get_user_meta() does.
                    continue;
                }
                $out[$id][$key][] = isset($row->meta_value) ? (string) $row->meta_value : '';
            }
        }

        return $out;
    }

    /**
     * The public shape from a full map: only pairs with rows.
     *
     * @param array<int, array<string, list<string>>> $source
     * @param list<int>    $ids
     * @param list<string> $keys
     * @return array<int, array<string, list<string>>>
     */
    private static function present(array $source, array $ids, array $keys): array
    {
        $out = [];
        foreach ($ids as $id) {
            foreach ($keys as $key) {
                $values = $source[$id][$key] ?? [];
                if ($values !== []) {
                    $out[$id][$key] = $values;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<int> $userIds
     * @return list<int>
     */
    private static function cleanIds(array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function cleanKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if ($key !== '') {
                $out[$key] = $key;
            }
        }

        return array_values($out);
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
            if (is_numeric($userId)) {
                self::forget((int) $userId);
            }
        };
        foreach (['added_user_meta', 'updated_user_meta', 'deleted_user_meta'] as $hook) {
            add_action($hook, $forget, 10, 2);
        }
    }
}
