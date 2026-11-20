<?php
/**
 * ProbesReads — run a SELECT and report whether its result can be TRUSTED.
 *
 * Extracted from PeepSoBlockRepository (F-99) so every bcc-core repository
 * that must tell "no rows" from "the read failed" uses ONE implementation
 * (F-300 added PeepSoMessageRepository). The docblocks below are the
 * originals; "blocked"/"not blocked" read as "rows"/"no rows" for other
 * callers.
 *
 * @package BCC\Core\Repositories
 */

namespace BCC\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

trait ProbesReads
{
    /**
     * Clear `$wpdb->last_error` so a FAILURE FROM AN EARLIER QUERY cannot
     * be mistaken for this one's.
     *
     * ⚠⚠ LOAD-BEARING, not tidiness. WordPress leaves `last_error` set
     * after any failed query anywhere earlier in the request. The callers
     * below fail CLOSED on a detected error, so without this one
     * unrelated failure would make every block read for the rest of that
     * request report "unknown" — withholding every message preview and
     * refusing every send until the request ended. A false positive here
     * is expensive, which is why there is a test for exactly this.
     *
     * ⚠ It is a named helper rather than an inline assignment for a
     * reason worth recording: assigning the literal in-line makes PHPStan
     * narrow the property to `''` and NOT widen it again across the
     * `get_var()` call, so the error check below was reported as
     * `notIdentical.alwaysFalse` — i.e. statically provable DEAD CODE.
     * It is not dead at runtime (real `wpdb::query()` sets `last_error`
     * on failure), but PHPStan cannot see that side effect, and a check
     * the analyser believes can never fire is a check nobody should
     * trust. Factoring both the clear and the read into their own scopes
     * states the dependency honestly instead of casting or suppressing.
     */
    private static function clearLastError(): void
    {
        global $wpdb;
        $wpdb->last_error = '';
    }

    /**
     * Did the most recent query report a driver error?
     *
     * Paired with `clearLastError()`: cleared before, checked after, so a
     * non-empty value can only have come from the query in between.
     */
    private static function lastReadFailed(): bool
    {
        global $wpdb;
        return (string) $wpdb->last_error !== '';
    }

    /**
     * Run a SELECT and report whether its result can be TRUSTED.
     *
     * Returns true when the rows are this query's and may be read with
     * `get_var(null)` / `get_col(null)`; false when the read failed and
     * the caller must answer "unknown".
     *
     * ⚠⚠ WHY `query()` IS CALLED EXPLICITLY RATHER THAN LETTING
     * `get_var($sql)` DO IT. `get_var()` and `get_col()` call `query()`
     * internally and then DISCARD its return value — and
     * `wpdb::query()` has failure paths that return false WITHOUT
     * setting `last_error`, so `last_error` alone is NOT an exhaustive
     * failure signal. In `class-wpdb.php` those are the `! $this->ready`
     * guard (a failed reconnect) and an empty result from the `query`
     * filter.
     *
     * ⚠⚠ AND BOTH OF THOSE RETURN **BEFORE** `flush()`, which is what
     * makes it worse than a missing null: `last_result` is not cleared
     * either, so it still holds the PREVIOUS query's rows. A probe that
     * trusted `get_var()` there could read a stale value from an
     * unrelated SELECT and answer a confident "blocked" — not merely
     * "not blocked". Checking `query()`'s own return is the only way to
     * see it.
     *
     * ⚠ THE COMPARISON MUST BE `=== false`, NOT `!$ok`. For a SELECT,
     * `query()` returns the ROW COUNT, so a legitimate "no such row"
     * returns 0 — and `!0` is true. Using a falsy test would report
     * every genuine "not blocked" as a failed read, which under
     * fail-closed callers means withholding every message and refusing
     * every send. There is a test for exactly that.
     */
    private static function runProbe(string $sql): bool
    {
        global $wpdb;

        self::clearLastError();
        $ok = $wpdb->query($sql);

        return $ok !== false && !self::lastReadFailed();
    }

    /**
     * Run a SELECT and return its rows, or null when they cannot be
     * TRUSTED (F-275).
     *
     * The row-returning sibling of `runProbe()`, with the same two checks
     * and the same reasons: `query()`'s own return value (the pre-`flush()`
     * bails that leave the PREVIOUS query's rows in `last_result`) and
     * `last_error`. A genuine empty result is `[]`, never null.
     *
     * ⚠ A non-array `last_result` after a successful query reads as `[]`,
     * matching what `get_results()` would have handed back.
     *
     * @return list<object>|null null = the read failed
     */
    private static function trustedRows(string $sql): ?array
    {
        global $wpdb;

        if (!self::runProbe($sql)) {
            return null;
        }
        $rows = $wpdb->last_result;

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * The same rows as ASSOCIATIVE ARRAYS, or null when they cannot be
     * TRUSTED — the `ARRAY_A` sibling of `trustedRows()` (F-472).
     *
     * ⚠⚠ WHY THIS EXISTS RATHER THAN CALLERS CASTING. Most reads that need
     * to tell "no rows" from "the read failed" were written as
     * `get_results($sql, ARRAY_A)` followed by a `last_error` check, and
     * their consumers index rows as `$row['col']`. Without an `ARRAY_A`
     * path, adopting this trait would mean rewriting every one of those
     * consumers to `$row->col` — a large diff through DTO hydration, for no
     * behavioural gain. This lets them delegate while their row shape stays
     * exactly what it was.
     *
     * The conversion is the one `wpdb::get_results()` performs for
     * `ARRAY_A`: `get_object_vars()` per row (wp-includes/class-wpdb.php —
     * the `ARRAY_A === $output` branch). So a caller that swaps
     * `get_results($sql, ARRAY_A)` for this gets the same arrays on the
     * success path, and `null` instead of a silently wrong answer on the
     * failure path.
     *
     * ⚠ Delegates to `trustedRows()` rather than repeating the checks, so
     * there is ONE failure path for both shapes. A genuine empty result is
     * `[]`, never null.
     *
     * @return list<array<string, mixed>>|null null = the read failed
     */
    private static function trustedRowsAssoc(string $sql): ?array
    {
        $rows = self::trustedRows($sql);
        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = is_object($row) ? get_object_vars($row) : (array) $row;
        }

        return $out;
    }
}
