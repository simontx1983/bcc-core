<?php
/**
 * Stubs for PeepSoGroupWriterLeaveRankTest.
 *
 * Unlike group-writer-stubs.php, the REAL PeepSoGroupRepository and
 * PeepSoGroupWriter run here, over a $wpdb double, so leave() decides from
 * the real duplicate-row rank. Loaded ONLY inside that test's subprocess
 * (RunTestsInSeparateProcesses), so the global PeepSo fakes cannot leak into
 * the main test process.
 *
 * State:
 *   $GLOBALS['__bcc_lr_rows']    list<string> gm_user_status per row of the
 *                                pair (user 7, group 42), in gm_id order
 *   $GLOBALS['__bcc_lr_leaves']  list<array{int,int}> member_leave() calls
 *   $GLOBALS['__bcc_lr_actions'] list<array> do_action() calls
 */

declare(strict_types=1);

namespace {
    if (!class_exists('PeepSoGroupUser')) {
        class PeepSoGroupUser
        {
            public function __construct(private int $groupId, private int $userId)
            {
            }

            /**
             * As PeepSo's (peepso-groups classes/models/groupuser.php): a raw
             * DELETE on (gm_user_id, gm_group_id), so EVERY row of the pair
             * goes, whatever its status.
             */
            public function member_leave(): bool
            {
                $GLOBALS['__bcc_lr_leaves'][] = [$this->groupId, $this->userId];
                $GLOBALS['__bcc_lr_rows']     = [];
                return true;
            }
        }
    }

    if (!function_exists('do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__bcc_lr_actions'][] = array_merge([$hook], $args);
        }
    }

    /**
     * $wpdb double. GET_LOCK / RELEASE_LOCK answer '1' (a free lock, as a
     * string, the way wpdb::get_var returns it); the pair read answers from
     * $GLOBALS['__bcc_lr_rows'] through query() + last_result, honouring the
     * LIMIT. Anything else is a test failure.
     */
    final class BccLeaveRankWpdb
    {
        public string $prefix = 'wp_';

        public string $last_error = '';

        /** @var list<object> */
        public array $last_result = [];

        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            $next = 0;
            $sql  = preg_replace_callback(
                '/%[ds]/',
                static function (array $m) use (&$next, $args): string {
                    $value = $args[$next++] ?? '<<UNBOUND>>';
                    return $m[0] === '%d' ? (string) (int) $value : "'" . (string) $value . "'";
                },
                $query
            );
            return (string) preg_replace('/\s+/', ' ', (string) $sql);
        }

        public function get_var(string $sql): ?string
        {
            if (str_contains($sql, 'GET_LOCK(') || str_contains($sql, 'RELEASE_LOCK(')) {
                return '1';
            }
            throw new \LogicException('unexpected get_var: ' . $sql);
        }

        public function query(string $sql): int
        {
            if (!str_contains($sql, 'WHERE gm_user_id = 7 AND gm_group_id = 42 ORDER BY gm_id ASC LIMIT ')) {
                throw new \LogicException('unexpected query: ' . $sql);
            }
            $rows = $GLOBALS['__bcc_lr_rows'] ?? [];
            if (preg_match('/LIMIT (\d+)/', $sql, $m) === 1) {
                $rows = array_slice($rows, 0, (int) $m[1]);
            }
            $this->last_error  = '';
            $this->last_result = array_map(static fn (string $s): object => (object) ['gm_user_status' => $s], $rows);
            return count($this->last_result);
        }
    }
}
