<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\PendingMembers;

use BCC\Core\Repositories\PeepSoGroupMembershipWriteRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * `$wpdb` double: renders each placeholder with its bound argument, and
 * models query() the way WPDB does — the result is the affected-row count
 * for a write, the row count for a SELECT (rows in last_result), or false
 * with last_error set on failure.
 */
final class PendingWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    /** @var list<object> */
    public array $last_result = [];

    /** @var list<string> */
    public array $queries = [];

    /** Rows the next SELECT yields. @var list<object> */
    public array $rows = [];

    /** What the next write reports as affected. */
    public int $affected = 1;

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
        if (str_starts_with(ltrim($sql), 'SELECT')) {
            $this->last_result = $this->rows;
            return count($this->rows);
        }
        return $this->affected;
    }
}

/**
 * F-272 repository layer.
 *
 * Reads (owner lists, count, my invites): the status filter — a request
 * list must never include banned or member rows — the bounds (§4), the
 * stable tiebreak, and FAIL-CLOSED: a failed read is null, never "none".
 *
 * Writes (PeepSoGroupMembershipWriteRepository): every statement names the
 * state it expects in its WHERE clause, so the row changes only if it is
 * still in that state at write time — a concurrent ban is never overwritten
 * — and a failed statement is false, never 0.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'listPendingMembers')]
#[CoversMethod(PeepSoGroupRepository::class, 'countPendingMembers')]
#[CoversMethod(PeepSoGroupRepository::class, 'listUserPendingInvites')]
#[CoversClass(PeepSoGroupMembershipWriteRepository::class)]
final class PendingMembersSqlTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb;

    private PendingWpdb $wpdb;

    protected function setUp(): void
    {
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new PendingWpdb();
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

    // ── reads ────────────────────────────────────────────────────────

    public function testRequestListFiltersExactlyOnPendingAdminForTheGroup(): void
    {
        PeepSoGroupRepository::listPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST, 0, 20);

        self::assertCount(1, $this->wpdb->queries);
        $sql = $this->wpdb->queries[0];
        self::assertStringContainsString("WHERE gm_group_id = 42 AND gm_user_status = 'pending_admin'", $sql);
        self::assertStringContainsString('ORDER BY gm_joined DESC, gm_id DESC LIMIT 20 OFFSET 0', $sql);
        self::assertStringContainsString('UNIX_TIMESTAMP(gm_joined) AS requested_utc', $sql);
    }

    public function testInviteListFiltersOnPendingUser(): void
    {
        PeepSoGroupRepository::listPendingMembers(42, PeepSoGroupRepository::PENDING_INVITE, 40, 20);

        self::assertStringContainsString("gm_user_status = 'pending_user'", $this->wpdb->queries[0]);
        self::assertStringContainsString('LIMIT 20 OFFSET 40', $this->wpdb->queries[0]);
    }

    public function testTheListIsCappedAtOneHundredAndNegativeOffsetsClamp(): void
    {
        PeepSoGroupRepository::listPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST, -5, 5000);

        self::assertStringContainsString('LIMIT 100 OFFSET 0', $this->wpdb->queries[0]);
    }

    public function testRowsComeBackFromTheRead(): void
    {
        $this->wpdb->rows = [(object) ['user_id' => '7', 'invited_by_id' => null, 'requested_utc' => '1700000000']];

        $rows = PeepSoGroupRepository::listPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST, 0, 20);

        self::assertSame($this->wpdb->rows, $rows);
    }

    public function testAnyOtherStatusIsRefusedWithoutAQuery(): void
    {
        foreach (['banned', 'member', 'member_owner', 'block_invites', ''] as $status) {
            self::assertSame([], PeepSoGroupRepository::listPendingMembers(42, $status, 0, 20));
            self::assertSame(0, PeepSoGroupRepository::countPendingMembers(42, $status));
        }
        self::assertSame([], $this->wpdb->queries);
    }

    public function testCountUsesTheSameFilter(): void
    {
        $this->wpdb->rows = [(object) ['n' => '3']];

        self::assertSame(3, PeepSoGroupRepository::countPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST));
        self::assertStringContainsString("WHERE gm_group_id = 42 AND gm_user_status = 'pending_admin'", $this->wpdb->queries[0]);
    }

    public function testUserInvitesAreThatUsersPendingUserRowsOnly(): void
    {
        $this->wpdb->rows = [(object) ['group_id' => '42', 'invited_by_id' => '3', 'invited_utc' => '1700000000']];

        $rows = PeepSoGroupRepository::listUserPendingInvites(7, 0, 500);

        self::assertCount(1, (array) $rows);
        $sql = $this->wpdb->queries[0];
        self::assertStringContainsString("WHERE gm_user_id = 7 AND gm_user_status = 'pending_user'", $sql);
        self::assertStringContainsString('ORDER BY gm_joined DESC, gm_id DESC LIMIT 100 OFFSET 0', $sql);
    }

    /** A failed read must not read as "nobody is waiting". */
    public function testAFailedReadIsNullNeverEmpty(): void
    {
        $this->wpdb->fail = true;

        self::assertNull(PeepSoGroupRepository::listPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST, 0, 20));
        self::assertNull(PeepSoGroupRepository::countPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST));
        self::assertNull(PeepSoGroupRepository::listUserPendingInvites(7, 0, 20));
    }

    public function testInvalidIdsRunNoQuery(): void
    {
        self::assertSame([], PeepSoGroupRepository::listPendingMembers(0, PeepSoGroupRepository::PENDING_REQUEST, 0, 20));
        self::assertSame([], PeepSoGroupRepository::listPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST, 0, 0));
        self::assertSame([], PeepSoGroupRepository::listUserPendingInvites(0, 0, 20));
        self::assertSame(0, PeepSoGroupRepository::countPendingMembers(-1, PeepSoGroupRepository::PENDING_INVITE));
        self::assertSame([], $this->wpdb->queries);
    }

    // ── conditional writes ───────────────────────────────────────────

    public function testInsertCreatesThePendingRowOnlyWhenThePairHasNoRowAtAll(): void
    {
        self::assertSame(1, PeepSoGroupMembershipWriteRepository::insertPendingIfAbsent(7, 42, 'pending_user', 3));

        $sql = $this->wpdb->queries[0];
        self::assertStringContainsString("SELECT 7, 42, 'pending_user', NULLIF(3, 0) FROM DUAL", $sql);
        // ANY existing row — banned included — blocks the insert.
        self::assertStringContainsString('WHERE NOT EXISTS ( SELECT 1 FROM wp_peepso_group_members WHERE gm_user_id = 7 AND gm_group_id = 42 )', $sql);
        self::assertStringNotContainsString("'member'", $sql, 'a pending door never writes a full member');
    }

    public function testInsertOfAnExistingPairReportsZero(): void
    {
        $this->wpdb->affected = 0;

        self::assertSame(0, PeepSoGroupMembershipWriteRepository::insertPendingIfAbsent(7, 42, 'pending_admin', null));
        self::assertStringContainsString('NULLIF(0, 0)', $this->wpdb->queries[0], 'a request records no inviter');
    }

    public function testPromoteOnlyMatchesARowStillInTheExpectedState(): void
    {
        self::assertSame(1, PeepSoGroupMembershipWriteRepository::promotePending(7, 42, 'pending_admin', 3));

        $sql = $this->wpdb->queries[0];
        self::assertStringContainsString("SET gm_user_status = 'member', gm_joined = CURRENT_TIMESTAMP, gm_accepted_by_id = NULLIF(3, 0)", $sql);
        self::assertStringContainsString("WHERE gm_user_id = 7 AND gm_group_id = 42 AND gm_user_status = 'pending_admin' LIMIT 1", $sql);
    }

    /** Race control: the row became banned (or anything else) before the write. */
    public function testAPromoteThatMatchesNoRowReportsZero(): void
    {
        $this->wpdb->affected = 0;

        self::assertSame(0, PeepSoGroupMembershipWriteRepository::promotePending(7, 42, 'pending_user', null));
    }

    public function testDeleteOnlyMatchesARowStillInTheExpectedState(): void
    {
        self::assertSame(1, PeepSoGroupMembershipWriteRepository::deletePending(7, 42, 'pending_user'));

        self::assertStringContainsString(
            "DELETE FROM wp_peepso_group_members WHERE gm_user_id = 7 AND gm_group_id = 42 AND gm_user_status = 'pending_user' LIMIT 1",
            $this->wpdb->queries[0]
        );
    }

    public function testAFailedWriteIsFalseNeverZero(): void
    {
        $this->wpdb->fail = true;

        self::assertFalse(PeepSoGroupMembershipWriteRepository::insertPendingIfAbsent(7, 42, 'pending_admin', null));
        self::assertFalse(PeepSoGroupMembershipWriteRepository::promotePending(7, 42, 'pending_admin', 3));
        self::assertFalse(PeepSoGroupMembershipWriteRepository::deletePending(7, 42, 'pending_admin'));
    }

    /** These writes never target a member, owner or banned row. */
    public function testNonPendingStatusesAreRefusedWithoutAQuery(): void
    {
        foreach (['member', 'member_owner', 'banned', 'block_invites', ''] as $status) {
            self::assertFalse(PeepSoGroupMembershipWriteRepository::insertPendingIfAbsent(7, 42, $status, null));
            self::assertFalse(PeepSoGroupMembershipWriteRepository::promotePending(7, 42, $status, null));
            self::assertFalse(PeepSoGroupMembershipWriteRepository::deletePending(7, 42, $status));
        }
        self::assertSame([], $this->wpdb->queries);
    }
}
