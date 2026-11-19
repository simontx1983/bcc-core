<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\GroupVisibility;

use BCC\Core\Contracts\ActivityReadFailedException;
use BCC\Core\Feed\ActivityFeedService;
use BCC\Core\Feed\GroupSyndicationGate;
use BCC\Core\PeepSo\PeepSoGraphService;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Tests\Stubs\RenderingWpdb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/rendering-wpdb.php';

/**
 * F-461: a post's group visibility is read IN the permalink's row, by the
 * same statement, so a failed meta read fails the row.
 *
 * ⚠⚠ THE DEFECT. getActivityById() decided "is this a group post" through
 * get_post_meta(). WordPress's update_meta_cache() caches an EMPTY meta array
 * for every id whose meta SELECT failed, and a persistent object cache keeps
 * it until the post is next edited. Through that cache a closed or secret
 * group's members-only post read as "not a group post", and the permalink
 * served it, content included, to anyone.
 *
 * ⚠⚠ AND THE ROW MUST BE THIS QUERY'S. wpdb::query() can bail BEFORE flush()
 * (a failed reconnect, an emptied statement), returning false with no
 * last_error while last_result still holds the PREVIOUS query's rows. A
 * get_row() read there would let someone else's row decide visibility, so
 * the row is read through ProbesReads::trustedRows(), and that case is
 * pinned below.
 */
#[CoversClass(PeepSoActivityRepository::class)]
#[CoversClass(ActivityFeedService::class)]
final class GroupVisibilityInRowTest extends TestCase
{
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

    // ── the repository ──────────────────────────────────────────────────

    public function testTheGroupFactsAreColumnsOfTheRowsOwnStatement(): void
    {
        $this->wpdb->resultsToReturn = [self::row(5, '7,12', 'members_only', '1')];

        $row = PeepSoActivityRepository::getById(5, withGroupVisibility: true);

        self::assertNotNull($row);
        self::assertSame('7,12', $row->bcc_group_ids);
        self::assertSame('members_only', $row->bcc_post_visibility);
        self::assertSame('1', $row->bcc_privacy_at_post);

        $sql = $this->wpdb->lastSql;
        self::assertSame(1, $this->wpdb->queryCount, 'one statement: the row and its group facts together');
        self::assertStringContainsString("GROUP_CONCAT(gm.meta_value ORDER BY gm.meta_id SEPARATOR ',')", $sql, 'every group id, as get_post_meta(…, false) returned them');
        foreach (["'peepso_group_id'", "'_bcc_post_visibility'", "'" . GroupSyndicationGate::META_PRIVACY_AT_POST . "'"] as $key) {
            self::assertStringContainsString($key, $sql);
        }
        foreach (['AS bcc_group_ids', 'AS bcc_post_visibility', 'AS bcc_privacy_at_post'] as $alias) {
            self::assertStringContainsString($alias, $sql);
        }
        self::assertStringContainsString('a.act_id = 5', $sql, 'the SELECT-list placeholders did not shift the WHERE values');
    }

    /**
     * ⚠⚠ The pre-flush bail: query() returns false with NO last_error, and
     * last_result (and get_row()) still hold the PREVIOUS query's row, here a
     * visible, ungrouped one. It must be a refusal, never that row.
     */
    public function testAPreFlushBailIsARefusalNotTheStaleRow(): void
    {
        $stale = self::row(999, null, null, null);
        $this->wpdb->last_result     = [$stale];
        $this->wpdb->rowToReturn     = $stale;
        // Real get_results() runs query() and hands back last_result, so on a
        // pre-flush bail it too returns the previous query's rows.
        $this->wpdb->resultsToReturn = [$stale];
        $this->wpdb->bailBeforeFlush = true;

        self::assertNull(PeepSoActivityRepository::getById(5, withGroupVisibility: true));
    }

    public function testAPreFlushBailThrowsWhenTheCallerAsks(): void
    {
        $stale = self::row(999, null, null, null);
        $this->wpdb->last_result     = [$stale];
        $this->wpdb->rowToReturn     = $stale;
        // Real get_results() runs query() and hands back last_result, so on a
        // pre-flush bail it too returns the previous query's rows.
        $this->wpdb->resultsToReturn = [$stale];
        $this->wpdb->bailBeforeFlush = true;

        $this->expectException(ActivityReadFailedException::class);
        PeepSoActivityRepository::getById(5, throwOnReadFailure: true, withGroupVisibility: true);
    }

    public function testAFailedReadIsARefusal(): void
    {
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        self::assertNull(PeepSoActivityRepository::getById(5, withGroupVisibility: true));
    }

    public function testAMissIsStillNull(): void
    {
        $this->wpdb->resultsToReturn = [];

        self::assertNull(PeepSoActivityRepository::getById(5, withGroupVisibility: true));
    }

    /** Every other caller is unchanged: no group columns unless asked. */
    public function testTheDefaultStatementCarriesNoGroupColumns(): void
    {
        $this->wpdb->rowToReturn = self::row(5, null, null, null);

        PeepSoActivityRepository::getById(5);

        self::assertStringNotContainsString('bcc_group_ids', $this->wpdb->lastSql);
    }

    // ── the permalink service ───────────────────────────────────────────

    public function testThePermalinkAsksForTheGroupFactsInItsRow(): void
    {
        $this->wpdb->resultsToReturn = [];

        self::assertPermalinkRefused(new ActivityFeedService(new PeepSoGraphService()));
        self::assertStringContainsString('AS bcc_group_ids', $this->wpdb->lastSql);
    }

    /**
     * The service end to end, through the repository: get_post_meta() is
     * poisoned (no group), the row says members-only in group 7, and the
     * permalink refuses before any hydration.
     */
    public function testAPoisonedMetaCacheCannotOpenAGroupPostByPermalink(): void
    {
        $GLOBALS['__test_post_meta'][123] = [];
        $this->wpdb->resultsToReturn = [self::row(5, '7', 'members_only', null)];

        self::assertPermalinkRefused(new ActivityFeedService(new PeepSoGraphService()));
    }

    public function testAPreFlushBailIsAFailedPermalinkReadForAServiceBuiltToThrow(): void
    {
        $stale = self::row(999, null, null, null);
        $this->wpdb->last_result     = [$stale];
        $this->wpdb->rowToReturn     = $stale;
        // Real get_results() runs query() and hands back last_result, so on a
        // pre-flush bail it too returns the previous query's rows.
        $this->wpdb->resultsToReturn = [$stale];
        $this->wpdb->bailBeforeFlush = true;

        self::assertPermalinkRefused(new ActivityFeedService(new PeepSoGraphService()));

        $this->expectException(ActivityReadFailedException::class);
        (new ActivityFeedService(new PeepSoGraphService(), true))->getActivityById(5, 1);
    }

    /**
     * The permalink refuses act 5 BEFORE hydration. This unit world cannot
     * host hydration (it needs WordPress), so reaching it — an \Error from a
     * missing WordPress function — IS the "it was not refused" outcome, and
     * is reported as that failed assertion rather than as an error.
     */
    private static function assertPermalinkRefused(ActivityFeedService $service): void
    {
        try {
            $item = $service->getActivityById(5, 1);
        } catch (\Error $e) {
            self::fail('the row was not refused: hydration ran (' . $e->getMessage() . ')');
        }
        self::assertNull($item);
    }

    private static function row(int $actId, ?string $groups, ?string $visibility, ?string $privacyAtPost): object
    {
        return (object) [
            'act_id'              => (string) $actId,
            'act_user_id'         => '42',
            'act_owner_id'        => '42',
            'act_module_id'       => '1',
            'act_external_id'     => '123',
            'act_time'            => '2026-01-01 00:00:00',
            'act_access'          => '10',
            'act_status'          => 'publish',
            'act_repost_id'       => '0',
            'bcc_group_ids'       => $groups,
            'bcc_post_visibility' => $visibility,
            'bcc_privacy_at_post' => $privacyAtPost,
        ];
    }
}
