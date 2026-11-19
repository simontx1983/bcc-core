<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\PrimaryHallRecipients;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/** `$wpdb` double: renders placeholders, records the SQL, answers get_col() like wpdb. */
final class PrimaryHallWpdb
{
    public string $prefix = 'wp_';

    public string $usermeta = 'wp_usermeta';

    public string $last_error = '';

    /** @var list<string> */
    public array $queries = [];

    /** @var list<string> what get_col() returns (numeric strings, as wpdb does) */
    public array $col = [];

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
    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;
        if ($this->fail) {
            // wpdb::get_col() answers [] on a failed query, never null.
            $this->last_error = 'simulated failure';
            return [];
        }
        return $this->col;
    }
}

/**
 * The primary-Hall bell's recipient read requires live membership.
 *
 * The bcc_primary_hall_group_id pointer is cleared only by BCC's own
 * leaveHall(). A member removed through PeepSo (the row deleted) or banned
 * (the row set to 'banned') kept the pointer, so dispatchPrimaryHallPostFor
 * kept sending them "@x posted in <Hall>" bells and pushes. The read now
 * also requires an active (member%) row and no banned row for the pair.
 *
 * This pins the statement's shape and bindings; the bcc-trust integration
 * test runs it against MySQL 8 and MariaDB with real rows.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'findUsersByPrimaryHall')]
final class PrimaryHallRecipientsSqlTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb;

    private PrimaryHallWpdb $wpdb;

    protected function setUp(): void
    {
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new PrimaryHallWpdb();
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

    private function sql(): string
    {
        PeepSoGroupRepository::findUsersByPrimaryHall(42, 500);
        self::assertCount(1, $this->wpdb->queries, 'one bounded read');
        return $this->wpdb->queries[0];
    }

    public function testTheReadStillKeysOnThePrimaryHallPointer(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString("FROM wp_usermeta um WHERE um.meta_key = 'bcc_primary_hall_group_id' AND um.meta_value = '42'", $sql);
        self::assertStringContainsString('ORDER BY um.user_id ASC LIMIT 500', $sql);
    }

    public function testARecipientMustHoldAnActiveMembershipRowInThatGroup(): void
    {
        self::assertStringContainsString(
            "AND EXISTS ( SELECT 1 FROM wp_peepso_group_members gm WHERE gm.gm_group_id = 42 AND gm.gm_user_id = um.user_id AND gm.gm_user_status LIKE 'member%' )",
            $this->sql()
        );
    }

    public function testABannedRowExcludesTheRecipientEvenBesideAnActiveOne(): void
    {
        self::assertStringContainsString(
            "AND NOT EXISTS ( SELECT 1 FROM wp_peepso_group_members gb WHERE gb.gm_group_id = 42 AND gb.gm_user_id = um.user_id AND gb.gm_user_status = 'banned' )",
            $this->sql()
        );
    }

    public function testIdsComeBackAsPositiveIntsInOrder(): void
    {
        $this->wpdb->col = ['5', '0', '9', 'x'];

        self::assertSame([5, 9], PeepSoGroupRepository::findUsersByPrimaryHall(42, 500));
    }

    public function testAFailedReadSendsToNobody(): void
    {
        $this->wpdb->col  = ['5'];
        $this->wpdb->fail = true;

        self::assertSame([], PeepSoGroupRepository::findUsersByPrimaryHall(42, 500));
    }

    public function testInvalidInputsRunNoQuery(): void
    {
        self::assertSame([], PeepSoGroupRepository::findUsersByPrimaryHall(0, 500));
        self::assertSame([], PeepSoGroupRepository::findUsersByPrimaryHall(42, 0));
        self::assertSame([], $this->wpdb->queries);
    }
}
