<?php

declare(strict_types=1);

namespace BCC\Core\Cron\Tests;

use BCC\Core\Cron\RateLimitRowCleanup;
use BCC\Core\Repositories\DbMetricsRepository;
use BCC\Core\Repositories\OptionCleanupRepository;
use PHPUnit\Framework\TestCase;

/**
 * F-126 — the rate-limit row GC deleted nothing.
 *
 * It selected rows with `option_name >= prefix AND < prefix.'~'`. Under
 * wp_options' utf8mb4_unicode_ci collation `~` sorts BELOW the hex digits
 * every bucket key continues with, so the range matched no bucket row
 * (proven on MariaDB 11.8: two expired rows, 0 in range, 0 deleted). It also
 * never covered the `_transient_timeout_bcc_rl_*` companions, and stopped at
 * a fixed 10,000 rows per range per tick. Pinned here with a recording $wpdb:
 *   - every statement selects by an ESCAPED prefix LIKE, never a '~' range,
 *     for all three row families, and keeps the expiry test;
 *   - the drain runs until a short batch, then moves to the next prefix;
 *   - a failed DELETE stops that prefix only and is reported;
 *   - it stops on the wall-clock budget, and on the per-prefix batch
 *     ceiling, reporting a backlog either way;
 *   - deleteByPrefix() and the health count use the same prefix LIKE.
 */
final class RateLimitRowCleanupTest extends TestCase
{
    /** @var object{options: string, statements: list<array{sql: string, args: list<mixed>}>, results: list<int|false>} */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new class {
            public string $options = 'wp_options';
            public string $last_error = '';
            /** @var list<array{sql: string, args: list<mixed>}> */
            public array $statements = [];
            /** @var list<int|false> scripted query() results, in order; 0 once exhausted */
            public array $results = [];

            /** WordPress's own esc_like(). */
            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            /** @param mixed ...$args */
            public function prepare(string $query, ...$args): string
            {
                $this->statements[] = ['sql' => $query, 'args' => $args];
                return 'prepared#' . (count($this->statements) - 1);
            }

            /** @return int|false */
            public function query(string $prepared)
            {
                return $this->results === [] ? 0 : array_shift($this->results);
            }

            public function get_var(string $prepared): string
            {
                return '7';
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** A clock that never runs out. */
    private static function frozenClock(): \Closure
    {
        return static fn (): float => 1000.0;
    }

    /** @return list<string> the LIKE argument of every statement */
    private function likeArgs(): array
    {
        return array_map(static fn (array $s): string => (string) $s['args'][0], $this->wpdb->statements);
    }

    public function testEveryRowFamilyIsSelectedByAnEscapedPrefixLikeNotATildeRange(): void
    {
        $result = RateLimitRowCleanup::run(self::frozenClock());

        self::assertSame(
            ['\\_bcc\\_rl\\_%', '\\_transient\\_bcc\\_rl\\_%', '\\_transient\\_timeout\\_bcc\\_rl\\_%'],
            $this->likeArgs(),
            'one DELETE per family, the timeout companions included'
        );
        foreach ($this->wpdb->statements as $s) {
            self::assertStringContainsString('option_name LIKE %s', $s['sql']);
            self::assertStringNotContainsString('option_name >=', $s['sql'], 'a ~ range matches nothing under a unicode collation');
            self::assertStringContainsString("CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < UNIX_TIMESTAMP()", $s['sql'], 'only EXPIRED rows go');
            self::assertSame(RateLimitRowCleanup::BATCH_SIZE, $s['args'][1]);
            foreach ($s['args'] as $arg) {
                self::assertStringNotContainsString('~', (string) $arg);
            }
        }
        self::assertSame(['deleted' => 0, 'failed' => [], 'backlog' => false], $result);
    }

    public function testAFullBatchKeepsDrainingAndAShortOneMovesOn(): void
    {
        $this->wpdb->results = [1000, 1000, 3, 0, 1000, 5];

        $result = RateLimitRowCleanup::run(self::frozenClock());

        self::assertSame(
            ['\\_bcc\\_rl\\_%', '\\_bcc\\_rl\\_%', '\\_bcc\\_rl\\_%', '\\_transient\\_bcc\\_rl\\_%', '\\_transient\\_timeout\\_bcc\\_rl\\_%', '\\_transient\\_timeout\\_bcc\\_rl\\_%'],
            $this->likeArgs()
        );
        self::assertSame(['deleted' => 3008, 'failed' => [], 'backlog' => false], $result);
    }

    public function testAFailedDeleteStopsThatPrefixOnlyAndIsReported(): void
    {
        $this->wpdb->results = [false, 2, 1];

        $result = RateLimitRowCleanup::run(self::frozenClock());

        self::assertSame(['\\_bcc\\_rl\\_%', '\\_transient\\_bcc\\_rl\\_%', '\\_transient\\_timeout\\_bcc\\_rl\\_%'], $this->likeArgs());
        self::assertSame(['deleted' => 3, 'failed' => ['_bcc_rl_'], 'backlog' => false], $result);
    }

    public function testTheWallClockBudgetEndsTheTickAndReportsABacklog(): void
    {
        $this->wpdb->results = array_fill(0, 50, 1000);
        $t = 0.0;
        // Every reading advances 5s: start, then one reading per batch.
        $clock = static function () use (&$t): float {
            $now = $t;
            $t  += 5.0;
            return $now;
        };

        $result = RateLimitRowCleanup::run($clock);

        // Deadline = 0 + 20s. Readings 5, 10, 15 run a batch; 20 stops.
        self::assertCount(3, $this->wpdb->statements);
        self::assertSame(['deleted' => 3000, 'failed' => [], 'backlog' => true], $result);
    }

    public function testThePerPrefixCeilingBoundsATickEvenWithAStoppedClock(): void
    {
        $this->wpdb->results = array_fill(0, 3 * RateLimitRowCleanup::MAX_BATCHES_PER_PREFIX + 10, 1000);

        $result = RateLimitRowCleanup::run(self::frozenClock());

        self::assertCount(3 * RateLimitRowCleanup::MAX_BATCHES_PER_PREFIX, $this->wpdb->statements, 'termination does not depend on the clock');
        self::assertSame(3 * RateLimitRowCleanup::MAX_BATCHES_PER_PREFIX * 1000, $result['deleted']);
        self::assertTrue($result['backlog']);
    }

    public function testDeleteByPrefixUsesTheSamePrefixLike(): void
    {
        $this->wpdb->results = [4];

        self::assertSame(4, OptionCleanupRepository::deleteByPrefix('_legacy_key_'));

        self::assertCount(1, $this->wpdb->statements);
        self::assertStringContainsString('option_name LIKE %s', $this->wpdb->statements[0]['sql']);
        self::assertStringNotContainsString('>=', $this->wpdb->statements[0]['sql']);
        self::assertSame(['\\_legacy\\_key\\_%', 1000], $this->wpdb->statements[0]['args']);
    }

    public function testTheHealthRowCountUsesTheSamePrefixLike(): void
    {
        self::assertSame(7, DbMetricsRepository::countOptionsWithPrefix('_transient_bcc_rl_'));

        self::assertStringContainsString('option_name LIKE %s', $this->wpdb->statements[0]['sql']);
        self::assertSame(['\\_transient\\_bcc\\_rl\\_%'], $this->wpdb->statements[0]['args']);
    }

    public function testAnEmptyPrefixDeletesNothing(): void
    {
        self::assertSame(0, OptionCleanupRepository::deleteExpiredByPrefix(''));
        self::assertSame(0, OptionCleanupRepository::deleteByPrefix(''));
        self::assertSame([], $this->wpdb->statements, 'an empty prefix must never become LIKE \'%\'');
    }
}
