<?php
/**
 * Capturing `$wpdb` double for the #122 blocked-sender SQL tests.
 *
 * Shared by BlockedSenderSetTest, UnreadCountsByConversationSqlTest and
 * UnreadConversationCountSqlTest. In the house style of
 * FeedModuleAllowlistTest's FeedCaptureWpdb: it records the placeholder
 * SQL and the bound params every `prepare()` receives and never runs SQL.
 *
 * Capturing rather than executing is the point. These are EXCLUSION
 * predicates, so a test that only inspected returned rows could pass while
 * the exclusion happened in PHP after retrieval, or while it matched the
 * wrong direction — both of which are the mistakes actually available
 * here. Asserting on the prepared WHERE clause pins the predicate to the
 * query.
 *
 * Guarded so a second require in the same process is harmless.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockedSender;

/**
 * WordPress defines ARRAY_A as the literal string 'ARRAY_A'.
 * `PeepSoMessageRepository::getUnreadCountsByConversation` passes it to
 * `get_results`, and bcc-core's pure-unit bootstrap loads no WordPress —
 * so without this the first unit test ever to call that method dies on an
 * undefined constant. Guarded, and in the global namespace, matching how
 * the bootstrap stubs WP_Error.
 */
if (!\defined('ARRAY_A')) {
    \define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists(__NAMESPACE__ . '\\CaptureWpdb', false)) {
    final class CaptureWpdb
    {
        public string $prefix = 'wp_';
        public string $posts  = 'wp_posts';
        public string $users  = 'wp_users';

        /** @var list<array{sql: string, args: list<mixed>}> */
        public array $prepared = [];

        /** @var list<string> */
        public array $colToReturn = [];

        /** @var list<array<string, string>> */
        public array $resultsToReturn = [];

        /** @var int|string|null */
        public $varToReturn = 0;

        /**
         * Real `wpdb::$last_error`.
         *
         * ⚠⚠ MODELLING THIS HONESTLY IS THE WHOLE POINT OF F-99's TESTS,
         * and a naive double cannot express the case that matters.
         * Production CLEARS `last_error` before each probe, so a test that
         * merely pre-sets this property is testing the STALE-error case,
         * not a real failure — the clear wipes it before the query runs.
         *
         * So a real driver failure is modelled by `$errorOnQuery`, which
         * the read methods assign to `last_error` WHEN THEY RUN, exactly
         * as `wpdb::query()` does after a failed statement. The two cases
         * are then distinguishable, and both are asserted.
         */
        public string $last_error = '';

        /**
         * A driver error RAISED BY the next read, or '' for success.
         * Models `wpdb::query()` setting `last_error` on failure.
         */
        public string $errorOnQuery = '';

        /**
         * `last_error` as observed at the START of each read, before
         * `$errorOnQuery` is applied.
         *
         * This is what proves production cleared it: if every recorded
         * value is '', no stale error could have been mistaken for this
         * query's failure. Asserting the clear from the OUTSIDE like this
         * is stronger than grepping the source for an assignment.
         *
         * @var list<string>
         */
        public array $errorSeenAtQuery = [];

        /** Record entry state, then apply any simulated driver error. */
        private function enterQuery(): void
        {
            $this->errorSeenAtQuery[] = $this->last_error;
            if ($this->errorOnQuery !== '') {
                $this->last_error = $this->errorOnQuery;
            }
        }

        /**
         * What `query()` returns: the ROW COUNT for a SELECT, or FALSE
         * for a failure.
         *
         * ⚠⚠ IT DEFAULTS TO 1, NOT TRUE, BECAUSE THE DISTINCTION IS THE
         * BUG. Real `wpdb::query()` returns the row count for a SELECT,
         * so a legitimate "no such row" returns **0** — and a caller
         * testing `!$ok` instead of `$ok === false` would read every
         * genuine "not blocked" as a failed read. The double can produce
         * 0 so that trap is testable.
         *
         * @var int|bool
         */
        public $queryReturn = 1;

        /** Every SQL string passed to `query()`. @var list<string> */
        public array $queried = [];

        /**
         * Real `wpdb::$last_result` — the rows the last query fetched.
         *
         * ⚠ REAL `wpdb` HOLDS OBJECTS HERE, and that matters rather than
         * being a detail: `get_results($sql, OBJECT)` hands this property
         * back directly, and production reads `$row->blk_user_id`. A
         * double that stored the seeded ARRAYS would make every such
         * read fail in a way production never does — so `query()` casts.
         *
         * @var list<object>
         */
        public array $last_result = [];

        /**
         * Run a query, as `wpdb::query()` does.
         *
         * ⚠⚠ THE POINT OF THIS METHOD: real `wpdb::query()` has failure
         * paths that return FALSE WITHOUT SETTING `last_error` — the
         * `! $this->ready` guard and an empty result from the `query`
         * filter — and BOTH return before `flush()`, so `last_result` is
         * not cleared either and still holds the PREVIOUS query's rows.
         * That is why `last_error` is not an exhaustive failure signal,
         * and why the repository checks this return value. Setting
         * `queryReturn = false` with `errorOnQuery = ''` reproduces
         * exactly that case.
         *
         * @return int|bool
         */
        public function query(string $sql)
        {
            $this->queried[] = $sql;
            $this->enterQuery();
            if ($this->errorOnQuery !== '') {
                // ⚠ Rows deliberately NOT cleared — see the note above on
                // the pre-flush bail. A stale `last_result` is part of
                // what the repository has to defend against.
                return false;
            }
            $this->last_result = array_map(
                static fn (array $r): object => (object) $r,
                $this->resultsToReturn
            );
            // ⚠ A false return models the pre-flush bail, so the rows
            // are deliberately left ALONE rather than cleared — a double
            // that helpfully emptied them would hide the stale-read
            // hazard this exists to expose.
            return $this->queryReturn;
        }

        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            $this->prepared[] = ['sql' => $query, 'args' => array_values($args)];
            return $query;
        }

        /**
         * @param string|null $sql null reads the rows the last query
         *                            left behind, as real wpdb does.
         * @return list<string>
         */
        public function get_col($sql = null): array
        {
            $this->enterQuery();
            // ⚠ Real `get_col()` returns an EMPTY ARRAY on failure, never
            // null — indistinguishable from "no rows" except through
            // `last_error`. That indistinguishability IS the bug F-99
            // reports, so the double must reproduce it rather than
            // helpfully returning something falsy-but-different.
            return $this->errorOnQuery !== '' ? [] : $this->colToReturn;
        }

        /**
         * @param string     $sql
         * @param int|string $output
         * @return list<array<string, string>>
         */
        public function get_results($sql, $output = 0): array
        {
            $this->enterQuery();
            return $this->errorOnQuery !== '' ? [] : $this->resultsToReturn;
        }

        /**
         * @param string|null $sql null reads the rows the last query
         *                            left behind, as real wpdb does.
         * @return int|string|null
         */
        public function get_var($sql = null)
        {
            $this->enterQuery();
            // ⚠ NULL on failure, which is the same value a genuine "no
            // such row" produces. Reproducing that collision is the point.
            return $this->errorOnQuery !== '' ? null : $this->varToReturn;
        }

        public function lastSql(): string
        {
            $last = $this->prepared[count($this->prepared) - 1] ?? null;
            return $last['sql'] ?? '';
        }

        /** @return list<mixed> */
        public function lastArgs(): array
        {
            $last = $this->prepared[count($this->prepared) - 1] ?? null;
            return $last['args'] ?? [];
        }

        /** Whitespace-collapsed SQL, for substring assertions. */
        public function lastSqlFlat(): string
        {
            return (string) preg_replace('/\s+/', ' ', $this->lastSql());
        }
    }
}
