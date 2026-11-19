<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\ReadFailure;

use BCC\Core\Contracts\ActivityReadFailedException;
use BCC\Core\Feed\ActivityFeedService;
use BCC\Core\PeepSo\PeepSoGraphService;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Tests\Stubs\RenderingWpdb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/rendering-wpdb.php';

/**
 * F-275: a feed read that did not run must be distinguishable from an
 * empty feed.
 *
 * ⚠⚠ THE DEFECT. `getActivities()` read its rows with `get_results()` and
 * returned `$rows ?: []`, and `getById()` returned `$row ?: null`. A
 * failed query yields `[]` / null from those calls, so a database fault
 * reached the member as "no posts" on every feed, or as a 404 on a
 * permalink, with nothing telling the client to try again.
 *
 * ⚠ The fix is OPT-IN on purpose. bcc-trust CI resolves bcc-core's DEFAULT
 * BRANCH, and a read that started throwing for every caller would turn
 * each one that does not catch it into a fatal. So the historical answer is
 * pinned here too, for the callers that do not ask.
 */
#[CoversClass(PeepSoActivityRepository::class)]
#[CoversClass(ActivityFeedService::class)]
final class ActivityReadFailureSignalTest extends TestCase
{
    private const DRIVER_ERROR = 'MySQL server has gone away';

    /** @var mixed */
    private $priorWpdb;

    private RenderingWpdb $wpdb;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/tests/Stubs/nonopen-groups-seed.php';
        \bcc_test_seed_nonopen_groups([]);
        $GLOBALS['__test_post_meta'] = [];

        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = new RenderingWpdb();
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

    // ── the list read ───────────────────────────────────────────────────

    public function testAFailedListReadThrowsWhenTheCallerAsks(): void
    {
        $this->wpdb->errorOnQuery = self::DRIVER_ERROR;

        $this->expectException(ActivityReadFailedException::class);
        self::listRead(true);
    }

    /**
     * Every existing caller: the historical answer, unchanged. Called the
     * way they call it, WITHOUT the argument, so the default is what is
     * pinned (bcc-trust's BlogService and FeedColdStartService do this).
     */
    public function testAFailedListReadIsStillEmptyForACallerThatDoesNotAsk(): void
    {
        $this->wpdb->errorOnQuery = self::DRIVER_ERROR;

        self::assertSame([], PeepSoActivityRepository::getActivities([3], null, null, null, 1));
        self::assertSame([], self::listRead(false));
    }

    /**
     * ⚠⚠ `last_error` alone is NOT an exhaustive failure signal: wpdb's
     * pre-flush bails return false with no error and leave the PREVIOUS
     * query's rows in `last_result`. Trusting those rows would serve an
     * unrelated read's results as this feed.
     */
    public function testAFailureWithNoDriverErrorStillThrowsAndNeverServesStaleRows(): void
    {
        $this->wpdb->last_result     = [self::row(999)];
        $this->wpdb->bailBeforeFlush = true;

        $this->expectException(ActivityReadFailedException::class);
        self::listRead(true);
    }

    /**
     * ⚠ The `=== false` trap: a SELECT that matches nothing returns 0 from
     * `query()`. Reading that as a failure would turn every empty feed
     * into a 503.
     */
    public function testAGenuineEmptyResultIsEmptyNotAFailure(): void
    {
        $this->wpdb->resultsToReturn = [];

        self::assertSame([], self::listRead(true));
    }

    public function testASuccessfulTrustedReadReturnsItsRows(): void
    {
        $this->wpdb->resultsToReturn = [self::row(11), self::row(12)];

        $rows = self::listRead(true);

        self::assertSame([11, 12], array_map(static fn (object $r): int => (int) $r->act_id, $rows));
    }

    /**
     * WordPress leaves `last_error` set after any earlier failed query in
     * the request. Without the clear, one unrelated failure would turn
     * every later feed read into a 503.
     */
    public function testAStaleErrorFromAnEarlierQueryIsNotThisReadsFailure(): void
    {
        $this->wpdb->last_error      = 'an earlier, unrelated failure';
        $this->wpdb->resultsToReturn = [self::row(11)];

        self::assertCount(1, self::listRead(true));
        self::assertSame([''], $this->wpdb->errorSeenAtQuery, 'last_error is cleared before the read');
    }

    /** Only how the result is read changes, never what is asked. */
    public function testTheTrustedReadRunsTheSameStatement(): void
    {
        self::listRead(false);
        $historical = $this->wpdb->lastSql;

        self::listRead(true);

        self::assertNotSame('', $historical);
        self::assertSame($historical, $this->wpdb->lastSql);
    }

    /** The `[]` short-circuits are genuine "no matches": no query, no throw. */
    public function testTheEmptyFilterShortCircuitsNeverThrow(): void
    {
        $this->wpdb->errorOnQuery = self::DRIVER_ERROR;

        self::assertSame([], PeepSoActivityRepository::getActivities([], throwOnReadFailure: true));
        self::assertSame([], PeepSoActivityRepository::getActivities(null, [], throwOnReadFailure: true));
        self::assertSame([], PeepSoActivityRepository::getActivities(onlyForPageId: 0, throwOnReadFailure: true));
        self::assertSame(0, $this->wpdb->queryCount);
    }

    // ── the single-post read ────────────────────────────────────────────

    public function testAFailedPermalinkReadThrowsWhenTheCallerAsks(): void
    {
        $this->wpdb->errorOnQuery = self::DRIVER_ERROR;

        $this->expectException(ActivityReadFailedException::class);
        PeepSoActivityRepository::getById(5, throwOnReadFailure: true);
    }

    public function testAFailedPermalinkReadIsStillNullForACallerThatDoesNotAsk(): void
    {
        $this->wpdb->errorOnQuery = self::DRIVER_ERROR;

        self::assertNull(PeepSoActivityRepository::getById(5));
    }

    public function testAPermalinkMissIsStillNullNotAFailure(): void
    {
        $this->wpdb->resultsToReturn = [];

        self::assertNull(PeepSoActivityRepository::getById(5, throwOnReadFailure: true));
    }

    public function testAPermalinkHitReturnsItsRow(): void
    {
        $this->wpdb->resultsToReturn = [self::row(5)];

        $row = PeepSoActivityRepository::getById(5, throwOnReadFailure: true);

        self::assertNotNull($row);
        self::assertSame(5, (int) $row->act_id);
    }

    // ── the service flag ────────────────────────────────────────────────

    public function testAServiceBuiltToThrowSurfacesAFailedFeedRead(): void
    {
        $this->wpdb->errorOnQuery = self::DRIVER_ERROR;
        $service = new ActivityFeedService(new PeepSoGraphService(), true);

        foreach (self::serviceReads() as $name => $read) {
            try {
                $read($service);
                self::fail($name . ' answered instead of reporting the failed read');
            } catch (ActivityReadFailedException) {
                // expected
            }
        }
        self::assertSame(4, $this->wpdb->queryCount, 'each read ran its query once');
    }

    /** The default service, as every other caller builds it, is unchanged. */
    public function testADefaultServiceStillAnswersEmptyOnAFailedRead(): void
    {
        $this->wpdb->errorOnQuery = self::DRIVER_ERROR;
        $service = new ActivityFeedService(new PeepSoGraphService());

        $empty = ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
        foreach (self::serviceReads() as $name => $read) {
            self::assertSame($name === 'permalink' ? null : $empty, $read($service), $name);
        }
    }

    public function testAServiceBuiltToThrowStillAnswersAGenuinelyEmptyFeed(): void
    {
        $this->wpdb->resultsToReturn = [];
        $service = new ActivityFeedService(new PeepSoGraphService(), true);

        $empty = ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
        foreach (self::serviceReads() as $name => $read) {
            self::assertSame($name === 'permalink' ? null : $empty, $read($service), $name);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return list<object> */
    private static function listRead(bool $throw): array
    {
        return PeepSoActivityRepository::getActivities(null, null, null, null, 10, throwOnReadFailure: $throw);
    }

    /**
     * The four reads bcc-trust's feed surfaces make.
     *
     * @return array<string, callable(ActivityFeedService): mixed>
     */
    private static function serviceReads(): array
    {
        return [
            'feed'      => static fn (ActivityFeedService $s): mixed => $s->getFeed(0, ActivityFeedService::SCOPE_FOR_YOU),
            'author'    => static fn (ActivityFeedService $s): mixed => $s->getActivityForAuthor(7, 0),
            'page'      => static fn (ActivityFeedService $s): mixed => $s->getActivityForPage(9, 0),
            'permalink' => static fn (ActivityFeedService $s): mixed => $s->getActivityById(5, 0),
        ];
    }

    private static function row(int $actId): object
    {
        return (object) [
            'act_id'          => $actId,
            'act_user_id'     => 3,
            'act_owner_id'    => 3,
            'act_module_id'   => '1',
            'act_external_id' => 100 + $actId,
            'act_time'        => '2026-11-18 10:00:00',
            'act_access'      => 10,
            'act_status'      => 'publish',
        ];
    }
}
