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

        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            $this->prepared[] = ['sql' => $query, 'args' => array_values($args)];
            return $query;
        }

        /**
         * @param string $sql
         * @return list<string>
         */
        public function get_col($sql): array
        {
            return $this->colToReturn;
        }

        /**
         * @param string     $sql
         * @param int|string $output
         * @return list<array<string, string>>
         */
        public function get_results($sql, $output = 0): array
        {
            return $this->resultsToReturn;
        }

        /**
         * @param string $sql
         * @return int|string|null
         */
        public function get_var($sql)
        {
            return $this->varToReturn;
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
