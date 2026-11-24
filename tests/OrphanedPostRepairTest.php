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
            'logs'            => [],
            'trash_throws'    => false,
            'log_throws'      => false,
            'schedule_throws' => false,
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
    // ── the contained steps (#178 review conditions) ─────────────────────

    /** @return list<string> every logged message, in order */
    private function logMessages(): array
    {
        return array_map(
            static fn (array $line): string => $line[0],
            $this->state()['logs']
        );
    }

    /** @return list<int> the post_id carried by every logged line, in order */
    private function loggedPostIds(): array
    {
        return array_map(
            static fn (array $line): int => (int) ($line[1]['post_id'] ?? 0),
            $this->state()['logs']
        );
    }

    private function assertLogged(string $needle, string $why): void
    {
        $hit = array_filter(
            $this->logMessages(),
            static fn (string $m): bool => str_contains($m, $needle)
        );
        self::assertNotSame([], $hit, $why . ' — logged: ' . implode(' | ', $this->logMessages()));
    }

    /**
     * ⛔ CONDITION 1 — a THROWING TRASH must still be probed and still queue.
     *
     * The trash fires post-write hooks, so any listener can throw. If that
     * escaped, two things would break at once: the guard's 'hijacked' refusal
     * would become a 500, and — worse — the retry would never be queued, so a
     * recoverable orphan would become a permanent one. That is the exact
     * failure this class exists to close, reached through its own repair.
     */
    public function testAThrowingTrashIsStillProbedAndStillQueues(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['trash_throws']         = true;

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame([[]], $s['pins'], 'the pin was held when the listener threw');
        self::assertSame(
            [['peepso_absence', 'orphan_repair_unconfirmed']],
            $s['metrics'],
            'a throwing trash is an UNCONFIRMED outcome, not a silent one'
        );
        self::assertCount(1, $s['scheduled'], 'and the retry must still be queued');
        $this->assertLogged('the trash write threw', 'the throw itself must be recorded');
        $this->assertLogged('could not be confirmed', 'and so must the outcome');
    }

    /**
     * ⛔ CONDITION 1 + 2 — a THROWING SCHEDULER must not propagate, and the
     * log must already name the post.
     *
     * `AsyncDispatcher::scheduleSingle()` is `void`, so a schedule that never
     * landed is INVISIBLE. This is exactly why the file log is written BEFORE
     * the scheduler is touched: when the queue is gone, that line is the only
     * durable record that this post still needs looking at.
     */
    public function testAThrowingSchedulerDoesNotPropagateAndThePostIsStillOnRecord(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;
        $GLOBALS['__bcc_orp']['schedule_throws']      = true;

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame([], $s['scheduled'], 'precondition: nothing was queued');
        self::assertSame(
            [['peepso_absence', 'orphan_repair_unconfirmed']],
            $s['metrics'],
            'the outcome was already reported before the queue was touched'
        );
        self::assertContains(
            self::POST_ID,
            $this->loggedPostIds(),
            'the log must name the post, because the queue did not keep it'
        );
        $this->assertLogged('could not be queued', 'the lost schedule must itself be visible');
    }

    /**
     * ⛔ CONDITION 1 — a THROWING PROBE is UNKNOWN, and unknown queues.
     *
     * A driver exception is not a "no". Treating a thrown read as settled
     * would be the original fail-open wearing a different hat.
     */
    public function testAThrowingProbeIsUnknownAndStillQueues(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;
        $GLOBALS['__bcc_orp']['modes']                = ['throw'];

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame(
            [['peepso_absence', 'orphan_repair_unconfirmed']],
            $s['metrics'],
            'a thrown read must report, not be mistaken for "gone"'
        );
        self::assertCount(1, $s['scheduled'], 'and must queue the retry');
        $this->assertLogged('the status read threw', 'the thrown read must be recorded');
    }

    /**
     * ⛔ CONDITION 1 — an UNWRITABLE LOG must not stop the repair.
     *
     * The log is the durable record, but it is not the repair. If the two are
     * wired so that losing the log loses the retry, the fix is worse than the
     * bug it closes.
     */
    public function testAnUnwritableLogNeverStopsTheRepair(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;
        $GLOBALS['__bcc_orp']['log_throws']           = true;

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $s = $this->state();
        self::assertSame([], $s['logs'], 'precondition: nothing could be logged');
        self::assertSame(
            [['peepso_absence', 'orphan_repair_unconfirmed']],
            $s['metrics'],
            'the metric must still be recorded'
        );
        self::assertCount(1, $s['scheduled'], 'and the retry must still be queued');
    }

    /**
     * ⛔ CONDITION 2 — the ops runbook reads post_ids out of these lines, so
     * the post_id has to BE there. Asserted on the context, not the message,
     * because an id interpolated into prose is not a field ops can grep.
     */
    public function testTheUnconfirmedLineCarriesThePostIdAndTheAttempt(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;

        OrphanedPostRepair::afterDestroy(self::POST_ID);

        $unconfirmed = array_values(array_filter(
            $this->state()['logs'],
            static fn (array $line): bool => str_contains($line[0], 'could not be confirmed')
        ));

        self::assertCount(1, $unconfirmed, 'exactly one unconfirmed line');
        self::assertSame(self::POST_ID, $unconfirmed[0][1]['post_id'] ?? null, 'post_id as a field');
        self::assertSame(1, $unconfirmed[0][1]['attempt'] ?? null, 'and which attempt it was');
    }

    /**
     * ⛔ CONDITION 2 — the give-up names the post too. This is the line that
     * matters most: after it, nothing is coming back for this post.
     */
    public function testTheAbandonedLineNamesThePost(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;

        OrphanedPostRepair::retry(self::POST_ID, 5);

        $this->assertLogged('gave up', 'abandoning must be on the file log, not only in a metric');
        self::assertContains(self::POST_ID, $this->loggedPostIds(), 'and must name the post');
        self::assertSame(
            [['peepso_absence', 'orphan_repair_abandoned']],
            $this->state()['metrics'],
            'the metric is unchanged by the new logging'
        );
    }

    /**
     * ⚠ A retry that is still unconfirmed LOGS per attempt but does NOT
     * re-record the metric — `afterDestroy()` already counted this orphan
     * once, and counting it again per attempt would multiply one orphan into
     * up to MAX_ATTEMPTS activations on /system/health.
     *
     * This is pinned because it is a deliberate asymmetry, and the kind a
     * later reader would "tidy up" into a double count.
     */
    public function testAnUnconfirmedRetryLogsButDoesNotReCountTheOrphan(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;

        OrphanedPostRepair::retry(self::POST_ID, 2);

        $s = $this->state();
        self::assertSame([], $s['metrics'], 'no second activation for the same orphan');
        self::assertContains(self::POST_ID, $this->loggedPostIds(), 'but the attempt is on record');
        self::assertCount(1, $s['scheduled'], 'and it reschedules');
        self::assertSame(3, $s['scheduled'][0][2][1], 'with the attempt counter advanced');
    }
    /**
     * ⛔ CONDITION 1, stated as the literal claim: with EVERY step rigged to
     * throw at once, neither entry point throws.
     *
     * ⚠ This is not just the seven tests above run together. With the log
     * also throwing, `trySchedule()`'s catch calls `tryLog()`, which throws
     * INSIDE the catch that is handling the scheduler's throw — a
     * throw-within-a-catch that no single-switch test reaches. If that path
     * were not itself contained, the one place guaranteed to be running
     * during a database outage would be the place that 500s.
     *
     * The assertion is deliberately weak on outcome and strong on survival:
     * when everything is broken there is nothing left to record, and the only
     * thing worth promising is that the caller's refusal still stands.
     */
    public function testNeitherEntryPointThrowsWhenEveryStepIsBroken(): void
    {
        $GLOBALS['__bcc_orp']['posts'][self::POST_ID] = 'publish';
        $GLOBALS['__bcc_orp']['post_read_fails']      = true;
        $GLOBALS['__bcc_orp']['trash_throws']         = true;
        $GLOBALS['__bcc_orp']['log_throws']           = true;
        $GLOBALS['__bcc_orp']['schedule_throws']      = true;
        // 5 probes across the 3 calls below, with room to spare.
        $GLOBALS['__bcc_orp']['modes']                = array_fill(0, 8, 'throw');

        OrphanedPostRepair::afterDestroy(self::POST_ID);
        OrphanedPostRepair::retry(self::POST_ID, 1);
        OrphanedPostRepair::retry(self::POST_ID, 5);

        self::assertSame(
            [],
            $this->state()['scheduled'],
            'precondition: the scheduler really was broken for all three calls'
        );
        self::assertSame(
            [],
            $this->state()['logs'],
            'precondition: the log really was unwritable'
        );
        self::assertTrue(true, 'reaching here at all is the assertion');
    }
}
