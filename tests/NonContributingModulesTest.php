<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockedSender;

use BCC\Core\Feed\FeedItemNormalizer;
use BCC\Core\Repositories\PeepSoActivityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/blocked-sender-capture-wpdb.php';

/**
 * F-345 — review replies (module 206) do NOT count toward a member's activity
 * streaks or contribution score (user decision 2026-11-12).
 *
 * The two aggregates that feed ShiftLogService, LivingService and
 * ContributionScoreService had no module filter at all. They now exclude
 * FeedItemNormalizer::NON_CONTRIBUTING_MODULE_IDS, and nothing else: every
 * module written today is outside that list, so existing counts are
 * unchanged by construction (pinned below), and only rows carrying 206 —
 * which no writer emitted before F-345 — are left out.
 */
#[CoversClass(PeepSoActivityRepository::class)]
#[CoversClass(FeedItemNormalizer::class)]
final class NonContributingModulesTest extends TestCase
{
    /** Every act_module_id a writer emits today, besides 206. */
    private const MODULES_WRITTEN_TODAY = [1, 4, 200, 201, 202, 203, 204, 205];

    /** @var mixed */
    private $priorWpdb = null;

    private CaptureWpdb $wpdb;

    protected function setUp(): void
    {
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new CaptureWpdb();
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

    public function testBothAggregatesExcludeReviewReplies(): void
    {
        PeepSoActivityRepository::aggregateByDay(7, '2026-01-01 00:00:00');
        $this->assertStringContainsString('AND a.act_module_id NOT IN (206)', $this->wpdb->lastSqlFlat());

        PeepSoActivityRepository::aggregateByModule(7, '2026-01-01 00:00:00');
        $this->assertStringContainsString('AND a.act_module_id NOT IN (206)', $this->wpdb->lastSqlFlat());
    }

    public function testTheExclusionSitsInsideTheWhereClauseBeforeTheGrouping(): void
    {
        PeepSoActivityRepository::aggregateByDay(7, '2026-01-01 00:00:00');
        $sql = $this->wpdb->lastSqlFlat();

        $this->assertLessThan(
            strpos($sql, 'GROUP BY'),
            strpos($sql, 'NOT IN (206)'),
            'the exclusion must filter rows, not follow the GROUP BY'
        );
        $this->assertSame([7, '2026-01-01 00:00:00', 400], $this->wpdb->lastArgs(), 'placeholders and arguments unchanged');
    }

    /**
     * "Existing counts unchanged": no module written today is excluded, so
     * for every row that existed before F-345 the aggregates return exactly
     * what they did.
     */
    public function testNoModuleWrittenTodayIsExcluded(): void
    {
        $this->assertSame([], array_values(array_intersect(self::MODULES_WRITTEN_TODAY, FeedItemNormalizer::NON_CONTRIBUTING_MODULE_IDS)));
        $this->assertSame([], array_values(array_intersect(FeedItemNormalizer::publicFeedModuleIds(), FeedItemNormalizer::NON_CONTRIBUTING_MODULE_IDS)));
    }

    public function testReviewRepliesStayOutOfEveryFeedToo(): void
    {
        foreach (FeedItemNormalizer::NON_CONTRIBUTING_MODULE_IDS as $id) {
            $this->assertArrayNotHasKey((string) $id, FeedItemNormalizer::MODULE_TO_KIND, "module {$id} must stay off the feed allowlist");
        }
    }
}
