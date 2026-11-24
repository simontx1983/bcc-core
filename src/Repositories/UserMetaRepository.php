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
 *
 * Also the shared member-name filter, {@see publicNameMatchSql()} with
 * {@see nameQueryLike()}: the SQL that finds a member by what other people
 * are shown for them.
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

    /** Longest member-name query, in characters; the rest is ignored. */
    public const NAME_QUERY_MAX = 100;

    /**
     * bcc-trust's @handle and "hide my real name" keys. Spelled out because
     * bcc-core cannot depend on bcc-trust, as ActivityFeedService does.
     */
    private const HANDLE_META           = 'bcc_handle';
    private const REAL_NAME_HIDDEN_META = 'bcc_privacy_real_name_hidden';

    /**
     * user => key => values, trusted reads only. A key read with no row
     * holds [], so a repeat costs nothing.
     *
     * @var array<int, array<string, list<string>>>
     */
    private static array $memo = [];

    private static bool $listening = false;

    /**
     * The LIKE argument for a name query, normalised the one way every
     * name search here normalises it: trimmed, one leading '@' dropped (so
     * "@alice" finds the handle "alice"), cut to NAME_QUERY_MAX characters,
     * and LIKE's wildcards escaped so they match themselves.
     *
     * NULL for an empty query: no filter. FALSE for a query that is not
     * valid UTF-8: it matches nothing. Bind the string to every `%s` of
     * {@see publicNameMatchSql()}, and to a page's title or slug where a
     * list shows those beside members.
     */
    public static function nameQueryLike(string $nameQuery): string|false|null
    {
        $query = trim($nameQuery);
        if (str_starts_with($query, '@')) {
            $query = trim(substr($query, 1));
        }
        if ($query === '') {
            return null;
        }
        if (preg_match('/^.{0,' . self::NAME_QUERY_MAX . '}/su', $query, $m) !== 1) {
            return false;
        }

        global $wpdb;

        return '%' . $wpdb->esc_like($m[0]) . '%';
    }

    /**
     * The ONE member-name filter for lists that show members (F-512): a
     * condition, `EXISTS (…)`, that is TRUE when the member in
     * `$userIdColumn` matches. It has two `%s` placeholders; bind
     * {@see nameQueryLike()} to both, in the caller's own prepare().
     *
     * It matches what other people are SHOWN for the member, and nothing
     * else:
     *   - the @handle: the first `bcc_handle` row, trimmed, or 'member-{id}'
     *     when there is none (bcc-trust's PublicIdentity::handle());
     *   - the display name, only where others see it: not empty, not the
     *     login (in any case), not shaped like a signup login (`u_` and 3 to
     *     20 of a-z, 0-9 or `-`, in any case, surrounding whitespace
     *     ignored), and no "hide my real name" row that is not plainly off.
     *     Those are the names the card replaces with the handle
     *     (bcc-trust's PublicIdentity::displayName()). Any value but '', 0,
     *     false, off or no counts as hidden. That is stricter than the flag
     *     read in PHP, so the filter can miss a name that is shown but never
     *     match one that is hidden.
     * Never the login, the email, or a hidden real name.
     *
     * The fragment contains no `%` apart from its two placeholders.
     *
     * @param string $userIdColumn a column reference such as `u.ID`, never input
     * @throws \InvalidArgumentException when `$userIdColumn` is not a column reference
     */
    public static function publicNameMatchSql(string $userIdColumn): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $userIdColumn) !== 1) {
            throw new \InvalidArgumentException('publicNameMatchSql() takes a column reference such as u.ID.');
        }

        global $wpdb;

        return "EXISTS (SELECT 1 FROM {$wpdb->users} AS bcc_nm_u"
            . " WHERE bcc_nm_u.ID = {$userIdColumn}"
            . " AND (COALESCE(NULLIF(TRIM((SELECT bcc_nm_h.meta_value FROM {$wpdb->usermeta} AS bcc_nm_h"
            . " WHERE bcc_nm_h.user_id = bcc_nm_u.ID AND bcc_nm_h.meta_key = '" . self::HANDLE_META . "'"
            . " ORDER BY bcc_nm_h.umeta_id ASC LIMIT 1)), ''), CONCAT('member-', bcc_nm_u.ID)) LIKE %s"
            . " OR (bcc_nm_u.display_name LIKE %s"
            . " AND TRIM(bcc_nm_u.display_name) <> ''"
            . " AND LOWER(TRIM(bcc_nm_u.display_name)) <> LOWER(TRIM(bcc_nm_u.user_login))"
            . " AND LOWER(bcc_nm_u.display_name) NOT REGEXP '^[[:space:]]*u_[a-z0-9-]{3,20}[[:space:]]*$'"
            . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS bcc_nm_r"
            . " WHERE bcc_nm_r.user_id = bcc_nm_u.ID AND bcc_nm_r.meta_key = '" . self::REAL_NAME_HIDDEN_META . "'"
            . " AND LOWER(TRIM(bcc_nm_r.meta_value)) NOT IN ('', '0', 'false', 'off', 'no')))))";
    }

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
