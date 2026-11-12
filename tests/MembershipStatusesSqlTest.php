<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\MembershipStatuses;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/** `$wpdb` double: renders placeholders, models query() + last_result + last_error. */
final class StatusesWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    /** @var list<object> */
    public array $last_result = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var list<object> */
    public array $rows = [];

    public bool $fail = false;

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
        TestCase::assertSame(count($args), $next, 'every bound argument must have a placeholder');
        return (string) preg_replace('/\s+/', ' ', (string) $sql);
    }

    public function query(string $sql): int|false
    {
        $this->queries[]   = $sql;
        $this->last_result = [];
        $this->last_error  = '';
        if ($this->fail) {
            $this->last_error = 'simulated failure';
            return false;
        }
        // Honour the statement's LIMIT, as MySQL would.
        $rows = preg_match('/LIMIT (\d+)/', $sql, $m) === 1 ? array_slice($this->rows, 0, (int) $m[1]) : $this->rows;
        $this->last_result = $rows;
        return count($rows);
    }
}

/**
 * F-244: one bounded read of the viewer's raw membership status per group,
 * so list surfaces can give the same join verdict as the group page. Any
 * status comes back (pending and banned included), and a failed read is
 * null — never [] — so no caller can guess a Join from a failure.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'findUserMembershipStatuses')]
final class MembershipStatusesSqlTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb;

    private StatusesWpdb $wpdb;

    protected function setUp(): void
    {
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new StatusesWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
    }

    public function testOneBoundedQueryForTheUserAndTheGroups(): void
    {
        PeepSoGroupRepository::findUserMembershipStatuses(7, [42, 43, 42, 0, -1]);

        self::assertCount(1, $this->wpdb->queries);
        $sql = $this->wpdb->queries[0];
        self::assertStringContainsString('SELECT gm_group_id, gm_user_status FROM wp_peepso_group_members', $sql);
        self::assertStringContainsString('WHERE gm_user_id = 7 AND gm_group_id IN (42,43)', $sql, 'deduplicated, non-positive ids dropped');
        self::assertStringContainsString('LIMIT 4', $sql, '2 × the two ids');
        self::assertStringNotContainsString('gm_user_status =', $sql, 'every status, not only active ones');
    }

    public function testEveryStatusComesBackKeyedByGroup(): void
    {
        $this->wpdb->rows = [
            (object) ['gm_group_id' => '42', 'gm_user_status' => 'member_owner'],
            (object) ['gm_group_id' => '43', 'gm_user_status' => 'pending_admin'],
            (object) ['gm_group_id' => '44', 'gm_user_status' => 'pending_user'],
            (object) ['gm_group_id' => '45', 'gm_user_status' => 'banned'],
        ];

        self::assertSame(
            [42 => 'member_owner', 43 => 'pending_admin', 44 => 'pending_user', 45 => 'banned'],
            PeepSoGroupRepository::findUserMembershipStatuses(7, [42, 43, 44, 45, 46])
        );
    }

    public function testAGroupWithNoRowIsAbsentNotNull(): void
    {
        self::assertSame([], PeepSoGroupRepository::findUserMembershipStatuses(7, [42]));
    }

    public function testAFailedReadIsNullNeverEmpty(): void
    {
        $this->wpdb->fail = true;

        self::assertNull(PeepSoGroupRepository::findUserMembershipStatuses(7, [42]));
    }

    public function testTheFirstRowWinsIfAPairHasTwo(): void
    {
        $this->wpdb->rows = [
            (object) ['gm_group_id' => '42', 'gm_user_status' => 'banned'],
            (object) ['gm_group_id' => '42', 'gm_user_status' => 'member'],
        ];

        self::assertSame([42 => 'banned'], PeepSoGroupRepository::findUserMembershipStatuses(7, [42]));
        self::assertStringContainsString('ORDER BY gm_id ASC', $this->wpdb->queries[0]);
    }

    public function testAtMostTwoHundredGroupsAreAskedFor(): void
    {
        PeepSoGroupRepository::findUserMembershipStatuses(7, range(1, 250));

        self::assertSame(200, substr_count($this->wpdb->queries[0], ',') + 1 - substr_count('gm_group_id, gm_user_status', ','));
        self::assertStringContainsString('LIMIT 400', $this->wpdb->queries[0]);
    }

    /**
     * No unique (user, group) index: a duplicated pair must not crowd a real
     * group out of the result. The limit leaves room for one duplicate per
     * id, so every group still comes back.
     */
    public function testADuplicatedPairDoesNotHideAnotherGroup(): void
    {
        $ids = range(1, 200);
        // Group 1 has a duplicate row; the double honours the SQL LIMIT.
        $rows = [(object) ['gm_group_id' => '1', 'gm_user_status' => 'member']];
        foreach ($ids as $id) {
            $rows[] = (object) ['gm_group_id' => (string) $id, 'gm_user_status' => 'member'];
        }
        $this->wpdb->rows = $rows;

        $statuses = PeepSoGroupRepository::findUserMembershipStatuses(7, $ids);

        preg_match('/LIMIT (\d+)/', $this->wpdb->queries[0], $m);
        $limit = (int) ($m[1] ?? 0);
        self::assertGreaterThanOrEqual(count($rows), $limit, 'room for every row, duplicate included');
        self::assertCount(200, (array) $statuses, 'group 200 is still read, not "no row"');
        self::assertSame('member', $statuses[200] ?? null);
    }

    public function testNoUserOrNoGroupsRunsNoQuery(): void
    {
        self::assertSame([], PeepSoGroupRepository::findUserMembershipStatuses(0, [42]));
        self::assertSame([], PeepSoGroupRepository::findUserMembershipStatuses(7, []));
        self::assertSame([], $this->wpdb->queries);
    }
}
