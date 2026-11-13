<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockPairs;

use BCC\Core\Repositories\PeepSoBlockRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/** `$wpdb` double: renders placeholders, models query() + last_result + last_error. */
final class BlockPairsWpdb
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
        $this->last_result = $this->rows;
        return count($this->rows);
    }
}

/**
 * F-261: the backfill walks every block row by keyset. Pins the ordering and
 * bound, the shape, and FAIL-CLOSED: a failed read is null, never "done".
 */
#[CoversMethod(PeepSoBlockRepository::class, 'listBlockPairsAfter')]
#[CoversMethod(PeepSoBlockRepository::class, 'listBlockPairsPage')]
final class BlockPairsKeysetSqlTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb;

    private BlockPairsWpdb $wpdb;

    protected function setUp(): void
    {
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new BlockPairsWpdb();
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

    public function testAKeysetPageInPrimaryKeyOrder(): void
    {
        PeepSoBlockRepository::listBlockPairsAfter(41, 100);

        self::assertStringContainsString(
            'SELECT blk_id, blk_user_id, blk_blocked_id FROM wp_peepso_blocks WHERE blk_id > 41 ORDER BY blk_id ASC LIMIT 100',
            $this->wpdb->queries[0]
        );
    }

    public function testThePageIsCappedAndTheCursorClamped(): void
    {
        PeepSoBlockRepository::listBlockPairsAfter(-3, 10000);

        self::assertStringContainsString('WHERE blk_id > 0 ORDER BY blk_id ASC LIMIT 500', $this->wpdb->queries[0]);
    }

    public function testRowsComeBackTypedAndBadRowsAreDropped(): void
    {
        $this->wpdb->rows = [
            (object) ['blk_id' => '5', 'blk_user_id' => '7', 'blk_blocked_id' => '9'],
            (object) ['blk_id' => '6', 'blk_user_id' => '0', 'blk_blocked_id' => '9'],
        ];

        self::assertSame(
            [['id' => 5, 'blocker' => 7, 'blocked' => 9]],
            PeepSoBlockRepository::listBlockPairsAfter(0)
        );
    }

    public function testAnEmptyPageMeansDone(): void
    {
        self::assertSame([], PeepSoBlockRepository::listBlockPairsAfter(99));
    }

    public function testAFailedReadIsNullNeverDone(): void
    {
        $this->wpdb->fail = true;

        self::assertNull(PeepSoBlockRepository::listBlockPairsAfter(0));
    }

    public function testThePageCursorIsTheHighestRawIdEvenWhenEveryRowIsDropped(): void
    {
        $this->wpdb->rows = [
            (object) ['blk_id' => '12', 'blk_user_id' => '0', 'blk_blocked_id' => '9'],
            (object) ['blk_id' => '13', 'blk_user_id' => '7', 'blk_blocked_id' => '0'],
        ];

        self::assertSame(
            ['pairs' => [], 'next' => 13, 'done' => false],
            PeepSoBlockRepository::listBlockPairsPage(11),
            'a page of only malformed rows still moves the walk past them'
        );
    }

    public function testThePageCursorCoversADroppedLastRow(): void
    {
        $this->wpdb->rows = [
            (object) ['blk_id' => '5', 'blk_user_id' => '7', 'blk_blocked_id' => '9'],
            (object) ['blk_id' => '8', 'blk_user_id' => '0', 'blk_blocked_id' => '9'],
        ];

        self::assertSame(
            ['pairs' => [['id' => 5, 'blocker' => 7, 'blocked' => 9]], 'next' => 8, 'done' => false],
            PeepSoBlockRepository::listBlockPairsPage(0)
        );
    }

    public function testOnlyAnEmptyReadIsDone(): void
    {
        self::assertSame(['pairs' => [], 'next' => 99, 'done' => true], PeepSoBlockRepository::listBlockPairsPage(99));
    }

    public function testAPageThatCannotAdvanceTheCursorIsNullNotALoop(): void
    {
        $this->wpdb->rows = [(object) ['blk_id' => null, 'blk_user_id' => '7', 'blk_blocked_id' => '9']];

        self::assertNull(PeepSoBlockRepository::listBlockPairsPage(4));
    }

    public function testAFailedPageReadIsNull(): void
    {
        $this->wpdb->fail = true;

        self::assertNull(PeepSoBlockRepository::listBlockPairsPage(0));
    }

    public function testThePageReadIsTheSameBoundedKeysetQuery(): void
    {
        PeepSoBlockRepository::listBlockPairsPage(-3, 10000);

        self::assertStringContainsString(
            'SELECT blk_id, blk_user_id, blk_blocked_id FROM wp_peepso_blocks WHERE blk_id > 0 ORDER BY blk_id ASC LIMIT 500',
            $this->wpdb->queries[0]
        );
    }
}
