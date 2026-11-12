<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * PeepSoGroupRepository::getMembershipStatusOrFail() — the membership read
 * the group write doors use. A read that did not run is FALSE, never null
 * ("no row"): a null would let join() flip a ban to membership and leave()
 * delete the banned row.
 */
#[CoversClass(PeepSoGroupRepository::class)]
final class MembershipStatusOrFailTest extends TestCase
{
    /** @var object{prefix: string, last_error: string, last_result: mixed, fail: bool, row: ?string, queries: int} */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var mixed */
            public $last_result = [];
            public bool $fail = false;
            public ?string $row = null;
            public int $queries = 0;

            /** @var list<string> */
            public array $col = [];
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $prepared = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = [$sql, $args];
                return $sql;
            }

            /** @return list<string> */
            public function get_col(string $sql): array
            {
                return $this->col;
            }

            /** @return int|false */
            public function query(string $sql)
            {
                $this->queries++;
                if ($this->fail) {
                    // wpdb keeps the PREVIOUS last_result on some failure paths.
                    $this->last_error = 'MySQL server has gone away';
                    return false;
                }
                $this->last_error  = '';
                $this->last_result = $this->row === null ? [] : [(object) ['gm_user_status' => $this->row]];
                return count($this->last_result);
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testARowReturnsItsStatus(): void
    {
        $this->wpdb->row = 'banned';
        self::assertSame('banned', PeepSoGroupRepository::getMembershipStatusOrFail(7, 42));
    }

    public function testNoRowIsNull(): void
    {
        self::assertNull(PeepSoGroupRepository::getMembershipStatusOrFail(7, 42));
    }

    public function testAFailedReadIsFalseEvenWithAStaleResultLeftBehind(): void
    {
        $this->wpdb->last_result = [(object) ['gm_user_status' => 'member']];
        $this->wpdb->fail        = true;

        self::assertFalse(PeepSoGroupRepository::getMembershipStatusOrFail(7, 42));
    }

    public function testBannedGroupIdsAreReadInOneBoundedQuery(): void
    {
        $this->wpdb->col = ['42', '43'];

        self::assertSame([42, 43], PeepSoGroupRepository::findUserBannedGroupIds(7, [42, 43, 44, 42, 0]));
        self::assertCount(1, $this->wpdb->prepared);
        self::assertStringContainsString("gm_user_status = 'banned'", $this->wpdb->prepared[0][0]);
        self::assertStringContainsString('LIMIT 200', $this->wpdb->prepared[0][0]);
        self::assertSame([7, 42, 43, 44], $this->wpdb->prepared[0][1], 'deduped, invalid ids dropped');
    }

    public function testNoGroupsMeansNoQuery(): void
    {
        self::assertSame([], PeepSoGroupRepository::findUserBannedGroupIds(7, [0, -3]));
        self::assertSame([], $this->wpdb->prepared);
    }

    public function testInvalidIdsDoNotQuery(): void
    {
        self::assertNull(PeepSoGroupRepository::getMembershipStatusOrFail(0, 42));
        self::assertSame(0, $this->wpdb->queries);
    }
}
