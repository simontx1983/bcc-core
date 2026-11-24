<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\PeepSo\OrphanedPostRepair;
use BCC\Core\Repositories\PeepSoActivityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-542 — a destroy that silently failed used to leave an orphaned group post.
 *
 * ── WHAT THE PROPERTY ACTUALLY IS ───────────────────────────────────
 *
 * `failClosedIfHijacked()` destroys a post it cannot vouch for and DISCARDS
 * the result, in the branch reached BECAUSE a read already failed. The writer
 * then refuses and never reaches `attachToGroup()`, so a surviving post
 * carries no group stamp and reads as an ordinary post on the member's wall.
 *
 * ⚠⚠ THE TRASH IS BEST-EFFORT, NOT A GUARANTEE, and these tests are written
 * to make that visible rather than to flatter it. `wp_update_post()` does its
 * own `get_post()` and returns 0 before touching a row when that answers null
 * — and a FAILED `get_post()` is indistinguishable from "already gone". So in
 * the very scenario that brings us here the repair can silently do nothing.
 * {@see testTheRepairCanSilentlyDoNothingWhichIsWhyTheRetryExists}.
 *
 * ⇒ The BOUNDED RETRY is the guarantee. It is tested as such.
 *
 * ⚠⚠ ISOLATED ON PURPOSE. The harness shadows `wp_update_post` inside
 * `BCC\Core\PeepSo` and fakes two REAL core classes (`DegradationMetrics`,
 * `AsyncDispatcher`) at file scope. In a shared process those definitions win
 * for every file that runs after this one, which is how a file-level stub
 * quietly makes someone else's assertions vacuous. The attribute is what makes
 * "it cannot leak" a fact instead of an argument.
 */
#[RunTestsInSeparateProcesses]
final class OrphanedPostRepairTest extends TestCase
{
    private const POST_ID = 7701;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        require_once __DIR__ . '/Stubs/orphan-repair-stubs.php';

        $GLOBALS['__bcc_orp'] = [
            'posts'           => [],
            'modes'           => [],
            'post_read_fails' => false,
            'updates'         => [],
            'metrics'         => [],
            'scheduled'       => [],
            'queries'         => 0,
            'pins'            => [],
        ];
        $GLOBALS['wpdb'] = new \BccOrpWpdb();

        // The pin's subject: a request that must not reach any listener.
        $_GET     = ['group_id' => '9001'];
        $_POST    = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_orp'], $GLOBALS['wpdb']);
        $_GET = $_POST = $_REQUEST = [];
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $s = $GLOBALS['__bcc_orp'];
        self::assertIsArray($s);

        return $s;
    }

    // ── the already-deleted case ────────────────────────────────────────

    /**
     * ⭐ The destroy WORKED, so the repair must be a true no-op: no row
     * created, nothing reported, nothing queued.
     *
     * Verified against wp-includes/post.php: `wp_update_post` returns 0 on a
     * null `get_post()` BEFORE any insert. The fixture has no such post, so
     * the write is attempted and changes nothing.
     */
    public function testAnAlreadyDeletedPostIsATrueNoOp(): void
    {
        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame([], $s['posts'], 'no row may be created');
        self::assertSame([], $s['metrics'], 'a successful destroy is not a degradation');
        self::assertSame([], $s['scheduled'], 'and nothing to retry');
    }

    // ── the post survived ──────────────────────────────────────────────

    /**
     * The destroy failed and the post is still live. The repair trashes it and
     * does NOT report, because the repair worked — the post can no longer read
     * as an ordinary wall post.
     */
    public function testASurvivingPostIsTrashedAndNeedsNoRetry(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame('trash', $s['posts'][self::POST_ID], 'the orphan must be trashed');
        self::assertSame([], $s['metrics'], 'a repair that worked is not a degradation');
        self::assertSame([], $s['scheduled']);
    }

    /**
     * ⭐ THE TRASH IS PINNED, asserted from INSIDE the write.
     *
     * `save_post` still fires, and PeepSo's `peepso/classes/blogposts.php:26`
     * listener has NO post-type guard — it runs on this write and can itself
     * call `wp_update_post()` on a linked post. So the request must be
     * neutralised while the write runs.
     *
     * ⚠ Asserted from the snapshot taken inside the write, not after: the
     * guard restores the request in a `finally`, so an assertion afterwards
     * sees the original whether the pin ran or not. The ORDERING is the
     * property.
     */
    public function testTheTrashWriteIsPinned(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertNotSame([], $s['pins'], 'the write must have happened at all');
        foreach ($s['pins'] as $i => $seen) {
            self::assertSame([], $seen, "write #{$i} saw the caller's request");
        }
        self::assertSame(['group_id' => '9001'], $_GET, 'and the request is restored afterwards');
    }

    // ── the silent no-op, and why the retry exists ─────────────────────

    /**
     * ⛔⛔ THE CASE THAT MAKES THE CRON THE GUARANTEE.
     *
     * `wp_update_post`'s own `get_post()` fails, so it returns 0 and changes
     * nothing — indistinguishable from "already gone". The post survives, the
     * repair silently did nothing, and the ONLY thing that saves it is the
     * queued retry. Nothing here asserts the trash worked, because it did not.
     */
    public function testTheRepairCanSilentlyDoNothingWhichIsWhyTheRetryExists(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID]   = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']        = true;

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame('publish', $s['posts'][self::POST_ID], 'the repair did NOT work');
        self::assertNotSame([], $s['updates'], 'though it was attempted');
        self::assertSame(
            [['peepso_absence', 'orphan_repair_unconfirmed']],
            $s['metrics'],
            'so it must be reported'
        );
        self::assertCount(1, $s['scheduled'], 'and queued for retry');
        self::assertSame(OrphanedPostRepair::RETRY_HOOK, $s['scheduled'][0][1]);
        self::assertSame([self::POST_ID, 1], $s['scheduled'][0][2], 'attempt 1, args by INDEX');
    }

    // ── an untrustworthy read ──────────────────────────────────────────

    /**
     * ⛔ AN UNREADABLE CONFIRMATION IS NOT A CONFIRMATION. Both failure modes
     * must report and queue rather than assume the repair landed.
     *
     * `error` is a driver failure; `preflush` is wpdb's bail, which returns a
     * row count with NO `last_error` while `last_result` still holds the
     * previous query's rows — the one a reader trusting `get_results()` plus
     * `last_error` would serve as a real answer.
     *
     * ⚠ The post is left ABSENT from the fixture here, so a guard that
     * trusted an empty result would call it "gone" and report nothing. Only
     * treating the read as untrustworthy produces the queue.
     */
    #[DataProvider('untrustedReads')]
    public function testAnUnreadableConfirmationQueuesTheRetry(string $mode): void
    {
        // The trash write itself is allowed to land; only the CHECK is broken.
        $GLOBALS['__bcc_orp']['modes'] = [$mode, $mode, $mode, $mode];

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame(
            [['peepso_absence', 'orphan_repair_unconfirmed']],
            $s['metrics'],
            'an unreadable confirmation must be reported, not assumed'
        );
        self::assertCount(1, $s['scheduled']);
    }

    /**
     * ⛔⛔ A STALE ROW THAT HAPPENS TO SAY 'trash' MUST NOT LOOK LIKE SUCCESS.
     *
     * ⚠ THIS TEST EXISTS BECAUSE A MUTANT SURVIVED. Dropping the ID check in
     * `postStatusOrUnknown()` left every other test green: under the ordinary
     * pre-flush row ('publish') BOTH versions queue the retry, one because the
     * read is untrusted and one because the post looks live, so nothing could
     * tell them apart.
     *
     * They diverge only when the stale row carries a status the repair treats
     * as DONE. Then the version without the ID check reads another query's
     * 'trash' as "already handled", records nothing, queues nothing, and
     * abandons a live orphan. That is a worse outcome than the bug F-542 set
     * out to fix, reached through a row that was never about this post.
     */
    public function testAStaleTrashRowIsNotMistakenForSuccess(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;
        $GLOBALS['__bcc_orp']['modes']                = ['preflush_trash', 'preflush_trash'];

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        self::assertSame(
            'publish',
            $this->state()['posts'][self::POST_ID],
            'precondition: the orphan really is still live'
        );
        self::assertSame(
            [['peepso_absence', 'orphan_repair_unconfirmed']],
            $this->state()['metrics'],
            "a stale 'trash' from another query must read as UNKNOWN, not as done"
        );
        self::assertCount(1, $this->state()['scheduled'], 'and the retry must be queued');
    }

    /** @return iterable<string, array{string}> */
    public static function untrustedReads(): iterable
    {
        yield 'a driver failure'   => ['error'];
        yield 'the pre-flush bail' => ['preflush'];
    }

    /**
     * ⚠ ANTI-VACUITY for the two above. With the read WORKING and the post
     * genuinely absent, nothing is reported and nothing is queued — so
     * "always queue" cannot be what makes them pass, and the queries really
     * happened.
     */
    public function testATrustedEmptyReadReportsNothing(): void
    {
        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame([], $s['metrics']);
        self::assertSame([], $s['scheduled']);
        self::assertGreaterThan(0, $s['queries'], 'the confirmation must actually query');
    }

    // ── the retry: the actual guarantee ────────────────────────────────

    /** A retry that finds the post gone stops, silently and without a metric. */
    public function testTheRetryStopsOnceThePostIsGone(): void
    {
        OrphanedPostRepair::retry(self::POST_ID, 2);

        $s = $this->state();
        self::assertSame([], $s['metrics']);
        self::assertSame([], $s['scheduled'], 'no further attempt');
    }

    /** A retry that CAN trash the survivor does so, and stops. */
    public function testTheRetryTrashesASurvivorAndStops(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';

        OrphanedPostRepair::retry(self::POST_ID, 2);

        $s = $this->state();
        self::assertSame('trash', $s['posts'][self::POST_ID]);
        self::assertSame([], $s['scheduled'], 'repaired, so nothing more to do');
        self::assertSame([], $s['metrics']);
    }

    /**
     * ⭐ Still unrepairable, under the bound: it reschedules with an
     * INCREMENTED attempt, and does not record the give-up metric yet.
     */
    public function testAnUnrepairableRetryReschedulesWithTheNextAttempt(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;

        OrphanedPostRepair::retry(self::POST_ID, 2);

        $s = $this->state();
        self::assertCount(1, $s['scheduled']);
        self::assertSame([self::POST_ID, 3], $s['scheduled'][0][2], 'attempt must increment');
        self::assertSame([], $s['metrics'], 'not abandoned yet');
    }

    /**
     * ⛔ BOUNDED, and the give-up is VISIBLE. A repair that retried forever
     * would turn a database fault into an unbounded queue; one that gave up
     * silently would hide an accumulating orphan. At the bound it records
     * `orphan_repair_abandoned` and schedules nothing.
     */
    public function testTheRetryIsBoundedAndSaysSoWhenItGivesUp(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;

        OrphanedPostRepair::retry(self::POST_ID, 5);

        $s = $this->state();
        self::assertSame(
            [['peepso_absence', 'orphan_repair_abandoned']],
            $s['metrics'],
            'giving up must be reported'
        );
        self::assertSame([], $s['scheduled'], 'and must not schedule again');
    }

    // ── the tri-state read itself ──────────────────────────────────────

    /**
     * ⚠ The read's three answers, asserted directly, because the repair's
     * whole shape depends on "could not tell" being distinguishable from
     * "gone" — the conflation that made this bug invisible.
     */
    public function testThePostStatusReadHasThreeAnswers(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        self::assertSame('publish', PeepSoActivityRepository::postStatusOrUnknown(self::POST_ID));

        unset($GLOBALS['__bcc_orp']['posts'][self::POST_ID]);
        self::assertSame(
            PeepSoActivityRepository::POST_GONE,
            PeepSoActivityRepository::postStatusOrUnknown(self::POST_ID),
            'a read that RAN and found nothing is "gone", not "unknown"'
        );

        $GLOBALS['__bcc_orp']['modes'] = ['error'];
        self::assertNull(
            PeepSoActivityRepository::postStatusOrUnknown(self::POST_ID),
            'a read that did not run is "unknown", not "gone"'
        );
    }
}
