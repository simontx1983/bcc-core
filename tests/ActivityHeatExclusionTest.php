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
    /** @var object{prepared: list<array{0: string, 1: list<mixed>}>} */
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

    public function testAnExclusionOfNothingButJunkIsNoExclusion(): void
    {
        // Deliberately hostile input.
        PeepSoGroupRepository::getActivityHeat([5], 7 * 86400, [0, -1, 'x']);
        [$sql] = $this->last();

        self::assertStringNotContainsString('NOT IN', $sql);
    }
}
