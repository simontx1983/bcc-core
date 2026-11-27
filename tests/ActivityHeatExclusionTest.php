<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * getActivityHeat()'s optional exclusion of activity ids: bcc-trust passes
 * the posts moderation has hidden (they stay `publish`), so the activity
 * counts exclude moderated posts.
 *
 * SOURCE-SHAPE tests, like GroupNameQueryTest: they pin the statement the
 * read builds. bcc-trust's integration suite runs it against MySQL 8 and
 * MariaDB (GroupActivityHeatIntegrationTest), counts included.
 */
#[CoversMethod(PeepSoGroupRepository::class, 'getActivityHeat')]
final class ActivityHeatExclusionTest extends TestCase
{
    /** @var object{prepared: list<array{0: string, 1: list<mixed>}>, last_error: string, last_result: list<object>, fail: ?string} */
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        $this->wpdb = new class {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $prepared = [];
            public string $last_error = '';
            /** @var list<object> */
            public array $last_result = [];
            /** null: the read runs; 'error': fails with last_error; 'silent': query() false, no error */
            public ?string $fail = null;

            /** @return int|false */
            public function query(string $sql)
            {
                if ($this->fail === 'error') {
                    $this->last_error = 'simulated';
                    return false;
                }
                if ($this->fail === 'silent') {
                    return false;
                }
                return count($this->last_result);
            }

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = [$sql, $args];
                return $sql;
            }

            /** @return list<object> */
            public function get_results(string $sql): array
            {
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function last(): array
    {
        $last = end($this->wpdb->prepared);
        self::assertIsArray($last);

        return $last;
    }

    public function testWithoutAnExclusionTheReadIsUnchanged(): void
    {
        PeepSoGroupRepository::getActivityHeat([5, 6]);
        [$sql, $args] = $this->last();

        self::assertStringNotContainsString('NOT IN', $sql);
        self::assertCount(3, $args, 'two group ids and the cutoff, as before');
    }

    public function testTheExcludedActivitiesAreLeftOutOfEveryNumber(): void
    {
        PeepSoGroupRepository::getActivityHeat([5], 7 * 86400, [901, 902]);
        [$sql] = $this->last();

        self::assertStringContainsString('AND a.act_id NOT IN (901,902)', $sql);
        // One WHERE clause: posts, actives and last_at all come from the
        // filtered rows.
        self::assertSame(1, substr_count($sql, 'NOT IN'));
        self::assertLessThan(strpos($sql, 'GROUP BY'), strpos($sql, 'NOT IN'), 'in the WHERE clause, before the grouping');
    }

    /** Integers only, each once: nothing else reaches the statement, and no `%` reaches prepare(). */
    public function testOnlyPositiveIntegersAreInlined(): void
    {
        // Deliberately hostile input.
        PeepSoGroupRepository::getActivityHeat([5], 7 * 86400, ['903', 903, 0, -4, '1) OR (1=1', '50%']);
        [$sql] = $this->last();

        self::assertStringContainsString('AND a.act_id NOT IN (903,1,50)', $sql);
        self::assertStringNotContainsString('OR (1=1', $sql);
        self::assertStringNotContainsString('%)', $sql);
    }

    /** A read that runs answers the map; no rows is an empty map, a real "no activity". */
    public function testAReadThatRunsAnswersTheMap(): void
    {
        $this->wpdb->last_result = [(object) ['group_id' => '5', 'posts' => '3', 'actives' => '2', 'last_at' => '2026-11-20 10:00:00']];
        $map = PeepSoGroupRepository::getActivityHeat([5]);
        self::assertNotNull($map);
        self::assertSame([3, 2, '2026-11-20 10:00:00'], [$map[5]->posts, $map[5]->actives, $map[5]->last_at]);

        $this->wpdb->last_result = [];
        self::assertSame([], PeepSoGroupRepository::getActivityHeat([5]));
    }

    /** FAILS CLOSED: a read that failed, or returned early without an error, is null, never an empty map. */
    public function testAFailedReadIsNullNeverAnEmptyMap(): void
    {
        foreach (['error', 'silent'] as $fault) {
            $this->wpdb->fail        = $fault;
            $this->wpdb->last_error  = '';
            $this->wpdb->last_result = [(object) ['group_id' => '5', 'posts' => '9', 'actives' => '9', 'last_at' => null]];
            self::assertNull(PeepSoGroupRepository::getActivityHeat([5]), "{$fault}: no stale rows, no empty map");
        }
    }

    public function testAnExclusionOfNothingButJunkIsNoExclusion(): void
    {
        // Deliberately hostile input.
        PeepSoGroupRepository::getActivityHeat([5], 7 * 86400, [0, -1, 'x']);
        [$sql] = $this->last();

        self::assertStringNotContainsString('NOT IN', $sql);
    }
}
