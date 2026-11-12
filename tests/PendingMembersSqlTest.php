<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\PendingMembers;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/** `$wpdb` double that renders each placeholder with its bound argument. */
final class PendingWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    /** @var list<string> */
    public array $queries = [];

    /** @var list<object> */
    public array $rows = [];

    public string $var = '0';

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

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->queries[] = $sql;

        return $this->rows;
    }

    public function get_var(string $sql): string
    {
        $this->queries[] = $sql;

        return $this->var;
    }
}

/**
 * F-272 pending lists: the owner's request/invite lists, their count, and a
 * user's open invitations. Pins the status filter (a request list must never
 * include banned or member rows), the bounds (§4) and the stable tiebreak.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'listPendingMembers')]
#[CoversMethod(PeepSoGroupRepository::class, 'countPendingMembers')]
#[CoversMethod(PeepSoGroupRepository::class, 'listUserPendingInvites')]
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
        $this->wpdb->var = '3';

        self::assertSame(3, PeepSoGroupRepository::countPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST));
        self::assertStringContainsString("WHERE gm_group_id = 42 AND gm_user_status = 'pending_admin'", $this->wpdb->queries[0]);
    }

    public function testUserInvitesAreThatUsersPendingUserRowsOnly(): void
    {
        $this->wpdb->rows = [(object) ['group_id' => '42', 'invited_by_id' => '3', 'invited_utc' => '1700000000']];

        $rows = PeepSoGroupRepository::listUserPendingInvites(7, 0, 500);

        self::assertCount(1, $rows);
        $sql = $this->wpdb->queries[0];
        self::assertStringContainsString("WHERE gm_user_id = 7 AND gm_user_status = 'pending_user'", $sql);
        self::assertStringContainsString('ORDER BY gm_joined DESC, gm_id DESC LIMIT 100 OFFSET 0', $sql);
    }

    public function testInvalidIdsRunNoQuery(): void
    {
        self::assertSame([], PeepSoGroupRepository::listPendingMembers(0, PeepSoGroupRepository::PENDING_REQUEST, 0, 20));
        self::assertSame([], PeepSoGroupRepository::listPendingMembers(42, PeepSoGroupRepository::PENDING_REQUEST, 0, 0));
        self::assertSame([], PeepSoGroupRepository::listUserPendingInvites(0, 0, 20));
        self::assertSame(0, PeepSoGroupRepository::countPendingMembers(-1, PeepSoGroupRepository::PENDING_INVITE));
        self::assertSame([], $this->wpdb->queries);
    }
}
