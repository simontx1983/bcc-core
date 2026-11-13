<?php
/**
 * PeepSoBlockRepository — read-only access to peepso_blocks for the
 * §K1 block surface.
 *
 * Bypasses PeepSo's `is_user_blocking` (which gates on the
 * `user_blocking_enable` admin option) so blocks are always queryable
 * regardless of admin settings — matching the PeepSoBlockWriter design.
 *
 * No writes — all writes route through PeepSoBlockWriter.
 *
 * @package BCC\Core\Repositories
 * @since V1 (2026-04, §K1 Phase A blocks)
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoBlockRepository
{
    private const TABLE_SUFFIX = 'peepso_blocks';

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    use ProbesReads;

    /**
     * Is $blockerId currently blocking $blockedId?
     *
     * One-way check — symmetric blocks are two rows. Use
     * `isMutuallyBlocked` if you need both directions.
     *
     * ⚠ FAIL-OPEN BY CONTRACT, and deliberately left that way. A read
     * failure is reported as `false` ("not blocked"), which for a gate is
     * the unsafe direction. Callers that must not fail open should use
     * `isBlockingOrUnknown()` and decide for themselves — the right
     * direction is NOT the same at every call site, which is why this is
     * not simply changed here. See that method's docblock.
     *
     * Kept so bcc-trust's existing callers keep compiling while they are
     * migrated one at a time: bcc-trust CI resolves bcc-core's DEFAULT
     * BRANCH at run time, so widening this signature would turn
     * bcc-trust `main` red at every caller the moment it landed here.
     */
    public static function isBlocking(int $blockerId, int $blockedId): bool
    {
        return self::isBlockingOrUnknown($blockerId, $blockedId) === true;
    }

    /**
     * `isBlocking()` that can say "I could not tell".
     *
     * Returns true (blocked), false (definitely not blocked), or NULL
     * when the read itself failed and the answer is unknown.
     *
     * ⚠⚠ WHY THIS EXISTS. `$wpdb->get_var()` returns null BOTH for "no
     * such row" and for a failed query, so the old `$found !== null`
     * reported a DB error as "not blocked" — opening every gate that
     * depends on it. The caller cannot fix that without being told, and
     * the correct direction differs per site: withholding a message
     * preview is cheap and safe, suppressing a notification is cheap,
     * but refusing every send or emptying a feed is not. So this reports
     * the uncertainty and each caller chooses.
     *
     * ⚠⚠ `last_error` IS CLEARED FIRST, and that is load-bearing rather
     * than tidy. WordPress leaves `last_error` set after any failed query
     * anywhere earlier in the request; without the clear, one unrelated
     * failure would make every probe for the rest of that request report
     * "unknown", and under fail-closed semantics that means withholding
     * every preview and refusing every send. A stale error must not read
     * as this query's failure, and a test asserts exactly that.
     *
     * ⚠ The argument guards still answer FALSE, not null. A
     * non-positive or self-referential pair is a definite "not blocked",
     * not an unknown — no query is issued and there is nothing to fail.
     */
    public static function isBlockingOrUnknown(int $blockerId, int $blockedId): ?bool
    {
        if ($blockerId <= 0 || $blockedId <= 0 || $blockerId === $blockedId) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        // Existence probe, not COUNT(*): COUNT scans every matching row
        // (its LIMIT binds to the one aggregate row), SELECT 1 stops at
        // the first hit.
        $sql = $wpdb->prepare(
            "SELECT 1 FROM {$table}
              WHERE blk_user_id = %d
                AND blk_blocked_id = %d
              LIMIT 1",
            $blockerId,
            $blockedId
        );

        if (!self::runProbe($sql)) {
            return null;
        }

        return $wpdb->get_var(null) !== null;
    }

    /**
     * Either-direction check — used for view-model gating ("don't show
     * Alice the post if she blocked Bob OR Bob blocked her").
     *
     * ⚠ Fail-open by contract, like `isBlocking()`. Use
     * `isMutuallyBlockedOrUnknown()` where that matters.
     */
    public static function isMutuallyBlocked(int $userA, int $userB): bool
    {
        return self::isMutuallyBlockedOrUnknown($userA, $userB) === true;
    }

    /**
     * `isMutuallyBlocked()` that can say "I could not tell".
     *
     * ⚠ NOTE WHAT "MUTUALLY" MEANS HERE: despite the name this is an
     * EITHER-DIRECTION probe — it is true when A blocked B *or* B blocked
     * A, not only when both did. A caller failing closed on null is
     * therefore refusing on "either party blocked, or we could not tell",
     * which is the intended reading for a send gate but is worth knowing
     * before reusing it anywhere else.
     *
     * See `isBlockingOrUnknown()` for why `last_error` is cleared first
     * and why the argument guards answer false rather than null.
     */
    public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
    {
        if ($userA <= 0 || $userB <= 0 || $userA === $userB) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        // Existence probe — see isBlocking().
        $sql = $wpdb->prepare(
            "SELECT 1 FROM {$table}
              WHERE (blk_user_id = %d AND blk_blocked_id = %d)
                 OR (blk_user_id = %d AND blk_blocked_id = %d)
              LIMIT 1",
            $userA, $userB,
            $userB, $userA
        );

        if (!self::runProbe($sql)) {
            return null;
        }

        return $wpdb->get_var(null) !== null;
    }

    /**
     * Batch sibling of `isMutuallyBlocked` — for one viewer against a
     * candidate set, which candidates are blocked in EITHER direction?
     *
     * One bounded pass instead of N existence probes; used by the
     * messaging eligibility batch (card lists resolve ≤ 50 page
     * operators per request). Exact same semantics as calling
     * `isMutuallyBlocked($userId, $otherId)` per candidate — no
     * truncation of the result relative to the input set.
     *
     * @param list<int> $otherIds candidate user ids (caller-bounded;
     *                            defensively capped at 500 like the
     *                            list reads below)
     * @return array<int, true> keyed by the OTHER user's id; a key is
     *                          present iff that pair is blocked in at
     *                          least one direction
     */
    public static function getMutuallyBlockedSet(int $userId, array $otherIds): array
    {
        return self::getMutuallyBlockedSetOrUnknown($userId, $otherIds) ?? [];
    }

    /**
     * `getMutuallyBlockedSet()` that can say "I could not tell".
     *
     * Returns the either-direction blocked subset, or NULL when the read
     * failed.
     *
     * ⚠⚠ WHY IT CANNOT COPY `getBlockedSetOrUnknown()`'s SHAPE, which
     * is the sibling it otherwise mirrors. That one finishes with
     * `get_col(null)` — read the rows `query()` already fetched. This
     * probe needs TWO columns (to fold a row back to "the other user"),
     * so its natural reader is `get_results()`, and **real
     * `wpdb::get_results(null)` RETURNS NULL**:
     * `class-wpdb.php:3150` is `if ($query) { ... } else { return null; }`.
     * Only `get_var()` and `get_col()` have a null form that reads
     * `last_result`.
     *
     * So it reads `$wpdb->last_result` DIRECTLY — which is exactly what
     * `get_results($sql, OBJECT)` returns (`:3156` hands back that same
     * property), minus the failure signal `get_results` discards. The
     * property is public in core (`:138`) and holds row objects.
     *
     * ⚠ The failure detection is NOT re-implemented here: `runProbe()`
     * owns it, including the check of `query()`'s own return value, which
     * `last_error` alone does not cover. See its docblock.
     *
     * ⚠ Callers that need "unknown" to mean something specific must
     * decide per site — `canMessageMany`'s batch answers `unavailable`
     * for every candidate, which is uniform and so leaks nothing about
     * any individual pair.
     *
     * @param list<int>|array<int|string, mixed> $otherIds
     * @return array<int, true>|null
     */
    public static function getMutuallyBlockedSetOrUnknown(int $userId, array $otherIds): ?array
    {
        if ($userId <= 0 || $otherIds === []) {
            return [];
        }
        $unique = [];
        foreach ($otherIds as $raw) {
            $id = (int) $raw;
            if ($id > 0 && $id !== $userId) {
                $unique[$id] = true;
            }
        }
        if ($unique === []) {
            return [];
        }
        $ids = array_slice(array_keys($unique), 0, 500);

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($ids), '%d'));

        // Both directions in one query: rows where the viewer blocks a
        // candidate OR a candidate blocks the viewer. Explicit columns
        // so the direction can be folded back to "the other user".
        $sql = $wpdb->prepare(
            "SELECT blk_user_id, blk_blocked_id FROM {$table}
              WHERE (blk_user_id = %d AND blk_blocked_id IN ({$ph}))
                 OR (blk_blocked_id = %d AND blk_user_id IN ({$ph}))",
            ...array_merge([$userId], $ids, [$userId], $ids)
        );

        if (!self::runProbe($sql)) {
            return null;
        }

        // ⚠ The shape is DECLARED rather than left as bare `object`:
        // PHPStan rejects property access on an undeclared `object`, and
        // the original `get_results()` call only type-checked because the
        // stub hands back a permissive `stdClass`. Naming the two columns
        // is both PHPStan-clean and a better record of what is read.
        /** @var list<object{blk_user_id: string, blk_blocked_id: string}> $rows */
        $rows = $wpdb->last_result;

        $out = [];
        foreach (($rows ?: []) as $row) {
            $blocker = (int) $row->blk_user_id;
            $blocked = (int) $row->blk_blocked_id;
            $other   = $blocker === $userId ? $blocked : $blocker;
            if ($other > 0) {
                $out[$other] = true;
            }
        }
        return $out;
    }

    /**
     * One-directional batch sibling of `isBlocking` — for one blocker
     * against a candidate set, which candidates does THIS user block?
     *
     * ⚠ Deliberately NOT `getMutuallyBlockedSet`. That one matches
     * either direction, which is wrong for a viewer-relative read
     * treatment: "the sender blocked me" must not hide the sender's
     * message from me. The two are one `OR` leg apart and the wrong
     * one silently over-matches, so they are separate methods rather
     * than a flag.
     *
     * ⚠ Also deliberately not `getBlockedIds`. That has the right
     * direction but returns the blocker's whole list under `LIMIT 500`,
     * so a user with more than 500 blocks would silently read as
     * "not blocked" for everyone past the cap. Intersecting with the
     * caller's candidates instead means the cap applies to the page
     * being rendered, not to the user's block list.
     *
     * @param list<int> $candidateIds candidate user ids (caller-bounded;
     *                                defensively capped at 500, as the
     *                                list reads below are)
     * @return array<int, true> keyed by candidate id; a key is present
     *                          iff $blockerId blocks that candidate
     */
    public static function getBlockedSet(int $blockerId, array $candidateIds): array
    {
        return self::getBlockedSetOrUnknown($blockerId, $candidateIds) ?? [];
    }

    /**
     * `getBlockedSet()` that can say "I could not tell".
     *
     * Returns the blocked subset, or NULL when the read failed.
     *
     * ⚠⚠ THIS IS THE #122 WITHHOLD PATH, and it is the clearest case
     * for failing closed. `get_col()` returns an EMPTY ARRAY both for "no
     * candidate is blocked" and for a failed query, and in this feature an
     * empty set means SHOW EVERY SENDER'S TEXT — exactly the disclosure
     * the feature exists to prevent. A caller that treats null as
     * "withhold everything" degrades to placeholders on a DB error, which
     * is ugly, safe and self-correcting.
     *
     * ⚠ `get_col()` never returns null in real `wpdb` — it builds and
     * returns an array unconditionally — so there is no null to inspect
     * here at all, and an empty array is indistinguishable from "no
     * candidate is blocked". The failure signal therefore comes entirely
     * from `runProbe()`, which checks `query()`'s OWN return value as
     * well as `last_error`; see its docblock for why `last_error` alone
     * is not exhaustive.
     *
     * ⚠ The early returns still answer `[]`, not null — no candidates
     * means a definite empty set, with no query issued and nothing to
     * fail.
     *
     * ⚠ PRE-EXISTING AND NOT FIXED HERE: the candidate list is sliced to
     * 500. A caller passing more than 500 candidates silently gets the
     * remainder treated as unblocked — the same silent-incompleteness
     * class as `getBlockedIds()`'s LIMIT, and a fail-OPEN that no error
     * detection can catch. Today's callers pass at most ~100 (the #122
     * inbox and thread batches), so it is latent; recorded rather than
     * widened, because changing it is a behaviour decision and not this
     * change's to make.
     *
     * @param list<int>|array<int|string, mixed> $candidateIds
     * @return array<int, true>|null
     */
    public static function getBlockedSetOrUnknown(int $blockerId, array $candidateIds): ?array
    {
        if ($blockerId <= 0 || $candidateIds === []) {
            return [];
        }
        $unique = [];
        foreach ($candidateIds as $raw) {
            $id = (int) $raw;
            if ($id > 0 && $id !== $blockerId) {
                $unique[$id] = true;
            }
        }
        if ($unique === []) {
            return [];
        }
        $ids = array_slice(array_keys($unique), 0, 500);

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($ids), '%d'));

        $sql = $wpdb->prepare(
            "SELECT blk_blocked_id FROM {$table}
              WHERE blk_user_id = %d
                AND blk_blocked_id IN ({$ph})",
            ...array_merge([$blockerId], $ids)
        );

        if (!self::runProbe($sql)) {
            return null;
        }

        /** @var list<string>|null $rows */
        $rows = $wpdb->get_col(null);

        $out = [];
        foreach (($rows ?: []) as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $out[$id] = true;
            }
        }
        return $out;
    }

    /**
     * One keyset page of ALL block rows, oldest first (F-261 backfill: every
     * existing block pair must have its follows removed). Pass the last
     * `id` seen as $afterId; an empty page means done.
     *
     * ⚠ Prefer {@see listBlockPairsPage()}: this drops malformed rows, so a
     * cursor taken from the last RETURNED id cannot step past a page made
     * only of dropped rows. Kept for callers already on it.
     *
     * @return list<array{id: int, blocker: int, blocked: int}>|null
     */
    public static function listBlockPairsAfter(int $afterId, int $limit = 500): ?array
    {
        $page = self::listBlockPairsPage($afterId, $limit);
        return $page === null ? null : $page['pairs'];
    }

    /**
     * One keyset page of ALL block rows with an EXACT cursor.
     *
     * `next` is the highest RAW blk_id the page read — including rows
     * dropped as malformed (a zero blocker or blocked) — so a page made
     * only of dropped rows still moves the walk forward. `done` is true
     * only when the read returned no rows at all.
     *
     * Bounded: LIMIT ≤ 500 per page, ordered by the blk_id primary key.
     * FAIL-CLOSED: null when the read did not run, and null when rows came
     * back but none carried a blk_id past $afterId (a cursor that cannot
     * advance would loop forever) — never a page that reads as "done".
     *
     * @return array{pairs: list<array{id: int, blocker: int, blocked: int}>, next: int, done: bool}|null
     */
    public static function listBlockPairsPage(int $afterId, int $limit = 500): ?array
    {
        $afterId = max(0, $afterId);
        $limit   = max(1, min(500, $limit));

        global $wpdb;
        $table = self::table();

        $ok = $wpdb->query($wpdb->prepare(
            "SELECT blk_id, blk_user_id, blk_blocked_id FROM {$table}
              WHERE blk_id > %d
              ORDER BY blk_id ASC
              LIMIT %d",
            $afterId,
            $limit
        ));
        if ($ok === false || (string) $wpdb->last_error !== '') {
            return null;
        }

        $rows = is_array($wpdb->last_result) ? $wpdb->last_result : [];
        if ($rows === []) {
            return ['pairs' => [], 'next' => $afterId, 'done' => true];
        }

        $out  = [];
        $next = $afterId;
        foreach ($rows as $row) {
            $id      = (int) ($row->blk_id ?? 0);
            $blocker = (int) ($row->blk_user_id ?? 0);
            $blocked = (int) ($row->blk_blocked_id ?? 0);
            $next    = max($next, $id);
            if ($id > 0 && $blocker > 0 && $blocked > 0) {
                $out[] = ['id' => $id, 'blocker' => $blocker, 'blocked' => $blocked];
            }
        }
        if ($next <= $afterId) {
            return null;
        }
        return ['pairs' => $out, 'next' => $next, 'done' => false];
    }

    /**
     * IDs of users $blockerId is blocking. Bounded by LIMIT to keep
     * the feed-exclusion merge predictable; users with > 500 blocks
     * are pathological and almost certainly an abuse signal.
     *
     * @return list<int>
     */
    public static function getBlockedIds(int $blockerId, int $limit = 500): array
    {
        return self::getBlockedIdsOrUnknown($blockerId, $limit) ?? [];
    }

    /**
     * getBlockedIds() for callers that must not read a failed query as
     * "blocks no one": NULL when the read did not run (F-331).
     *
     * getBlockedIds() was a get_col() read, which returns [] on a failed
     * query (wpdb ALWAYS returns an array there), so a DB blip silently
     * dropped every block from an exclusion list. bcc-trust's notification
     * reads (F-296) must exclude blocked members or fail; they call this.
     * The old method keeps its fail-OPEN answer by delegating with `?? []`.
     *
     * @return list<int>|null
     */
    public static function getBlockedIdsOrUnknown(int $blockerId, int $limit = 500): ?array
    {
        if ($blockerId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        $sql = $wpdb->prepare(
            "SELECT blk_blocked_id FROM {$table}
              WHERE blk_user_id = %d
              LIMIT %d",
            $blockerId,
            $limit
        );
        if (!self::runProbe($sql)) {
            return null;
        }
        return self::positiveIdsFromLastResult();
    }

    /**
     * IDs of users who are blocking $blockedId. Used to suppress the
     * blocked-by-them user from seeing the blocker (mutual invisibility).
     *
     * @return list<int>
     */
    public static function getBlockerIds(int $blockedId, int $limit = 500): array
    {
        return self::getBlockerIdsOrUnknown($blockedId, $limit) ?? [];
    }

    /**
     * getBlockerIds() that answers NULL when the read did not run (F-331);
     * see getBlockedIdsOrUnknown().
     *
     * @return list<int>|null
     */
    public static function getBlockerIdsOrUnknown(int $blockedId, int $limit = 500): ?array
    {
        if ($blockedId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        $sql = $wpdb->prepare(
            "SELECT blk_user_id FROM {$table}
              WHERE blk_blocked_id = %d
              LIMIT %d",
            $blockedId,
            $limit
        );
        if (!self::runProbe($sql)) {
            return null;
        }
        return self::positiveIdsFromLastResult();
    }

    /**
     * The first column of the probe's rows as positive ints (runProbe() has
     * just confirmed they are this query's).
     *
     * @return list<int>
     */
    private static function positiveIdsFromLastResult(): array
    {
        global $wpdb;
        /** @var list<string>|null $rows */
        $rows = $wpdb->get_col(null);

        $ids = [];
        foreach (($rows ?: []) as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Count of blocks by $blockerId. Cheap aggregate.
     */
    public static function countByBlocker(int $blockerId): int
    {
        if ($blockerId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE blk_user_id = %d",
            $blockerId
        ));
    }

    /**
     * Hydrated list of blocks for the /me/blocks settings page —
     * joins wp_users for display name + user_login.
     *
     * @return list<object{
     *   blk_blocked_id: int|numeric-string,
     *   user_login: string|null,
     *   display_name: string|null,
     *   blk_id: int|numeric-string
     * }>
     */
    public static function listForBlocker(int $blockerId, int $limit, int $offset): array
    {
        if ($blockerId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT b.blk_id, b.blk_blocked_id, u.user_login, u.display_name
               FROM {$table} b
               LEFT JOIN {$wpdb->users} u ON u.ID = b.blk_blocked_id
              WHERE b.blk_user_id = %d
              ORDER BY b.blk_id DESC
              LIMIT %d OFFSET %d",
            $blockerId,
            $limit,
            $offset
        ));

        /** @var list<object{
         *   blk_blocked_id: int|numeric-string,
         *   user_login: string|null,
         *   display_name: string|null,
         *   blk_id: int|numeric-string
         * }> $rows
         */
        return $rows ?: [];
    }
}
