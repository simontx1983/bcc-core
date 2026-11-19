<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\DoorReads;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `$wpdb` double: renders placeholders; models get_col() and query() + last_result over the same rows. */
final class DoorReadsWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    /** @var list<object> */
    public array $last_result = [];

    /** @var list<string> */
    public array $queries = [];

    /** @var list<string> gm_user_status per row, in gm_id order */
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

    /** @return list<string> */
    private function limited(string $sql): array
    {
        return preg_match('/LIMIT (\d+)/', $sql, $m) === 1 ? array_slice($this->rows, 0, (int) $m[1]) : $this->rows;
    }

    /** @return list<string> */
    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;
        if ($this->fail) {
            $this->last_error = 'simulated failure';
            return [];
        }
        return $this->limited($sql);
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
        $this->last_result = array_map(static fn (string $s): object => (object) ['gm_user_status' => $s], $this->limited($sql));
        return count($this->last_result);
    }
}

/**
 * F-354 follow-up: the write doors' single-pair reads resolve a duplicated
 * (user, group) pair by the SAME rank as findUserMembershipStatuses —
 * banned > member_owner > other active (member%) > anything else, the
 * lowest gm_id breaking a tie — so a door never decides from a different
 * row than the lists show, a ban is never outranked by a stale member row,
 * and an owner row is never hidden behind one.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'getMembershipStatus')]
#[CoversMethod(PeepSoGroupRepository::class, 'getMembershipStatusOrFail')]
final class MembershipDoorReadsRankTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb;

    private DoorReadsWpdb $wpdb;

    protected function setUp(): void
    {
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new DoorReadsWpdb();
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

    /** @return array<string, array{list<string>, string}> */
    public static function duplicatePairs(): array
    {
        return [
            'stale request, then joined' => [['pending_admin', 'member'], 'member'],
            'joined, then stale invite'  => [['member_owner', 'pending_user'], 'member_owner'],
            'ban beside a stale member'  => [['member', 'banned'], 'banned'],
            'ban first'                  => [['banned', 'member_moderator'], 'banned'],
            'two active: lowest gm_id'   => [['member_manager', 'member'], 'member_manager'],
            // An owner row outranks another active row in EITHER order:
            // read as 'member', leave() would let member_leave() delete the
            // owner row along with the stray one.
            'owner behind an older member'    => [['member', 'member_owner'], 'member_owner'],
            'owner ahead of a member'         => [['member_owner', 'member'], 'member_owner'],
            'owner behind a moderator'        => [['member_moderator', 'member_owner'], 'member_owner'],
            'owner behind a stale request'    => [['pending_admin', 'member_owner'], 'member_owner'],
            'ban still beats an owner row'    => [['member_owner', 'banned'], 'banned'],
            'ban ahead of an owner row'       => [['banned', 'member_owner'], 'banned'],
            'two inactive: lowest gm_id' => [['pending_user', 'pending_admin'], 'pending_user'],
            'single row'                 => [['pending_admin'], 'pending_admin'],
        ];
    }

    /** @param list<string> $rows */
    #[DataProvider('duplicatePairs')]
    public function testBothDoorReadsPickTheSameRankedRow(array $rows, string $expected): void
    {
        $this->wpdb->rows = $rows;

        self::assertSame($expected, PeepSoGroupRepository::getMembershipStatus(7, 42));
        self::assertSame($expected, PeepSoGroupRepository::getMembershipStatusOrFail(7, 42));
    }

    /** @param list<string> $rows */
    #[DataProvider('duplicatePairs')]
    public function testTheDoorsAgreeWithTheBatchedListRead(array $rows, string $expected): void
    {
        $this->wpdb->rows = $rows;
        $single           = PeepSoGroupRepository::getMembershipStatusOrFail(7, 42);

        // The batched read's double row shape differs; feed it the same rows.
        $GLOBALS['wpdb'] = new class ($rows) {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var list<object> */
            public array $last_result = [];

            /** @param list<string> $rows */
            public function __construct(private array $rows)
            {
            }

            /** @param mixed ...$args */
            public function prepare(string $q, ...$args): string
            {
                return $q;
            }

            public function query(string $sql): int
            {
                $this->last_result = array_map(static fn (string $s): object => (object) ['gm_group_id' => '42', 'gm_user_status' => $s], $this->rows);
                return count($this->last_result);
            }
        };

        self::assertSame([42 => $single], PeepSoGroupRepository::findUserMembershipStatuses(7, [42]));
        self::assertSame($expected, $single);
    }

    public function testEachReadIsOneBoundedPairQueryInGmIdOrder(): void
    {
        PeepSoGroupRepository::getMembershipStatus(7, 42);
        PeepSoGroupRepository::getMembershipStatusOrFail(7, 42);

        self::assertCount(2, $this->wpdb->queries);
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringContainsString('WHERE gm_user_id = 7 AND gm_group_id = 42 ORDER BY gm_id ASC LIMIT 10', $sql);
        }
    }

    public function testNoRowIsNullForBoth(): void
    {
        self::assertNull(PeepSoGroupRepository::getMembershipStatus(7, 42));
        self::assertNull(PeepSoGroupRepository::getMembershipStatusOrFail(7, 42));
    }

    public function testAFailedReadIsStillFalseForTheDoorsVariant(): void
    {
        $this->wpdb->rows = ['member'];
        $this->wpdb->fail = true;

        self::assertFalse(PeepSoGroupRepository::getMembershipStatusOrFail(7, 42));
        self::assertNull(PeepSoGroupRepository::getMembershipStatus(7, 42), 'the plain read was always null on failure');
    }
}
