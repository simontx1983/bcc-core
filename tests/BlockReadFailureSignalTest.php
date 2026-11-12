<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockedSender;

use BCC\Core\Repositories\PeepSoBlockRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/blocked-sender-capture-wpdb.php';

/**
 * F-99 — a failed block read must be distinguishable from "not blocked".
 *
 * ⚠⚠ THE DEFECT. `$wpdb->get_var()` returns null BOTH for "no such row"
 * and for a failed query, and `$wpdb->get_col()` returns an empty array
 * for both. So `isBlocking()` reported a DB error as "not blocked" and
 * `getBlockedSet()` reported one as "nobody is blocked" — opening every
 * gate built on them, including #122's withhold path, the two
 * notification vetoes and the 1-on-1 send gate.
 *
 * ⚠ The fix is NOT to make the repository substitute a safe value,
 * because there is no single safe direction. Withholding a message
 * preview is cheap; refusing every send is not. So the repository
 * reports UNKNOWN as null and each caller decides. These tests pin the
 * signal; the per-site directions are pinned in bcc-trust.
 *
 * ⚠⚠ AND THE SIGNATURES ARE ADDITIVE ON PURPOSE. The old methods keep
 * their exact `bool`/`array` contracts and their fail-open behaviour,
 * because bcc-trust CI resolves bcc-core's DEFAULT BRANCH at run time —
 * widening `isBlocking(): ?bool` here would turn bcc-trust `main` red at
 * every caller the moment it landed, which is precisely the cross-repo
 * trap that cost this project a whole detour earlier today. The old
 * methods DELEGATE to the new ones, so there is one implementation
 * rather than two copies of the SQL (§11), and the cases below assert
 * their behaviour is unchanged.
 */
#[CoversClass(PeepSoBlockRepository::class)]
final class BlockReadFailureSignalTest extends TestCase
{
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

    // ── the signal ──────────────────────────────────────────────────

    public function testAFailedProbeIsUnknownNotUnblocked(): void
    {
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        $this->assertNull(
            PeepSoBlockRepository::isBlockingOrUnknown(7, 9),
            'A failed read must be UNKNOWN. Reporting it as false is the fail-open this fixes.'
        );
        $this->assertNull(PeepSoBlockRepository::isMutuallyBlockedOrUnknown(7, 9));
        $this->assertNull(PeepSoBlockRepository::getBlockedSetOrUnknown(7, [9, 10]));
        $this->assertNull(PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown(7, [9, 10]));
    }

    /**
     * ⚠ The collision the defect rests on: a genuine "no such row" also
     * yields null from `get_var`. It must read as FALSE, not unknown, or
     * fail-closed callers would withhold everything all the time.
     */
    public function testAGenuineNoRowIsFalseNotUnknown(): void
    {
        $this->wpdb->varToReturn = null;   // no row
        $this->wpdb->errorOnQuery = '';    // and no error

        $this->assertFalse(PeepSoBlockRepository::isBlockingOrUnknown(7, 9));
        $this->assertFalse(PeepSoBlockRepository::isMutuallyBlockedOrUnknown(7, 9));
    }

    /** And an empty result set is an empty SET, not unknown. */
    public function testAnEmptyResultIsAnEmptySetNotUnknown(): void
    {
        $this->wpdb->colToReturn  = [];
        $this->wpdb->errorOnQuery = '';

        $this->assertSame([], PeepSoBlockRepository::getBlockedSetOrUnknown(7, [9, 10]));
        $this->assertSame([], PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown(7, [9, 10]));
    }

    public function testASuccessfulProbeStillAnswersTrue(): void
    {
        $this->wpdb->varToReturn = 1;

        $this->assertTrue(PeepSoBlockRepository::isBlockingOrUnknown(7, 9));
        $this->assertTrue(PeepSoBlockRepository::isMutuallyBlockedOrUnknown(7, 9));
    }

    public function testASuccessfulSetReadStillAnswersTheSubset(): void
    {
        $this->wpdb->colToReturn = ['9', '11'];

        $this->assertSame(
            [9 => true, 11 => true],
            PeepSoBlockRepository::getBlockedSetOrUnknown(7, [9, 10, 11])
        );
    }

    // ── ⚠⚠ query() FAILS WITHOUT last_error — the non-exhaustive case ─

    /**
     * ⚠⚠ `last_error` IS NOT AN EXHAUSTIVE FAILURE SIGNAL, which is why
     * the probe checks `query()`'s own return value too.
     *
     * `wpdb::query()` has two paths that return FALSE WITHOUT setting
     * `last_error`: the `! $this->ready` guard (a failed reconnect) and
     * an empty result from the `query` filter. `get_var()`/`get_col()`
     * call `query()` internally and DISCARD its return, so a probe
     * relying on `last_error` alone would answer "not blocked" / `[]`
     * on both — the exact fail-open this whole change exists to close,
     * surviving in two places.
     *
     * ⚠ AND IT IS WORSE THAN A MISSING NULL. Both of those returns
     * happen BEFORE `flush()`, so `last_result` is not cleared either
     * and still holds the PREVIOUS query's rows. A probe that trusted
     * `get_var()` there could read a stale value from an unrelated
     * SELECT and answer a confident "BLOCKED". The double models that
     * faithfully: a false return leaves its rows alone rather than
     * helpfully emptying them.
     *
     * @param string $method
     */
    #[DataProvider('probeProvider')]
    public function testAQueryFailureWithNoLastErrorIsStillUnknown(string $method): void
    {
        $this->wpdb->queryReturn  = false;   // query() failed
        $this->wpdb->errorOnQuery = '';      // and set NO last_error
        // Stale rows an earlier SELECT left behind, which the unflushed
        // failure path would expose:
        $this->wpdb->varToReturn  = 1;
        $this->wpdb->colToReturn  = ['9'];

        $out = PeepSoBlockRepository::$method(7, str_ends_with($method, 'SetOrUnknown') ? [9] : 9);

        $this->assertNull(
            $out,
            'query() returning false must be detected even with last_error empty, or the stale '
            . 'rows above would be read as a confident answer.'
        );
    }

    /**
     * ⚠⚠ THE `=== false` vs `!$ok` TRAP, and it fails CLOSED, which
     * makes it expensive. For a SELECT `query()` returns the ROW COUNT,
     * so a legitimate "no such row" returns **0**. A probe written
     * `if (!$ok)` would treat every genuine "not blocked" as a failed
     * read — and with fail-closed callers that means withholding every
     * message preview and refusing every send, permanently, for
     * everyone who has blocked nobody. Which is almost everyone.
     */
    public function testZeroRowsIsNotAFailure(): void
    {
        $this->wpdb->queryReturn  = 0;     // SELECT matched nothing
        $this->wpdb->errorOnQuery = '';
        $this->wpdb->varToReturn  = null;  // ... and so no value
        $this->wpdb->colToReturn  = [];

        $this->assertFalse(
            PeepSoBlockRepository::isBlockingOrUnknown(7, 9),
            'Zero rows is a definite "not blocked", not a failed read.'
        );
        $this->assertFalse(PeepSoBlockRepository::isMutuallyBlockedOrUnknown(7, 9));
        $this->assertSame([], PeepSoBlockRepository::getBlockedSetOrUnknown(7, [9]));
    }

    /**
     * ⚠ The probe must issue the SQL through `query()` — not through
     * `get_var($sql)`, whose discarded return is the whole defect. Pinned
     * so nobody "simplifies" it back.
     */
    public function testTheProbeGoesThroughQueryNotThroughGetVar(): void
    {
        PeepSoBlockRepository::isBlockingOrUnknown(7, 9);

        $this->assertCount(1, $this->wpdb->queried, 'The SQL must be run via query().');
        $this->assertStringContainsString('blk_blocked_id = %d', $this->wpdb->queried[0]);
    }

    // ── ⚠⚠ THE STALE-ERROR CONTROL, the one that matters most ───────

    /**
     * ⚠⚠ WordPress leaves `last_error` SET after any failed query
     * anywhere earlier in the request. If these probes did not clear it
     * first, one unrelated failure would make every block read for the
     * rest of that request report UNKNOWN — and under fail-closed
     * semantics that means withholding every message preview and
     * refusing every send until the request ends.
     *
     * So a false positive here is expensive, and this is the control
     * that catches it. The house pattern this was modelled on
     * (`AdvisoryLock::release`) does NOT clear first; bcc-trust's own
     * repositories do.
     *
     * @param string $method
     */
    #[DataProvider('probeProvider')]
    public function testAStaleErrorFromAnEarlierQueryIsNotThisQuerysFailure(string $method): void
    {
        // Something else failed earlier in the request.
        $this->wpdb->last_error   = 'Duplicate entry for key PRIMARY';
        // This query itself succeeds.
        $this->wpdb->errorOnQuery = '';
        $this->wpdb->varToReturn  = 1;
        $this->wpdb->colToReturn  = ['9'];

        $out = PeepSoBlockRepository::$method(7, str_ends_with($method, 'SetOrUnknown') ? [9] : 9);

        $this->assertNotNull(
            $out,
            'A stale error from an unrelated earlier query must not be read as this query failing.'
        );
    }

    /**
     * And the clear is asserted from the OUTSIDE — by what the double
     * observed at query time — rather than by grepping the source for an
     * assignment, which would pass against a clear that ran too late.
     *
     * @param string $method
     */
    #[DataProvider('probeProvider')]
    public function testLastErrorIsAlreadyClearedWhenTheQueryRuns(string $method): void
    {
        $this->wpdb->last_error  = 'stale from earlier';
        $this->wpdb->varToReturn = 1;
        $this->wpdb->colToReturn = ['9'];

        PeepSoBlockRepository::$method(7, str_ends_with($method, 'SetOrUnknown') ? [9] : 9);

        $this->assertNotSame([], $this->wpdb->errorSeenAtQuery, 'The probe must actually have queried.');
        $this->assertSame(
            array_fill(0, count($this->wpdb->errorSeenAtQuery), ''),
            $this->wpdb->errorSeenAtQuery,
            'Every query must start with last_error empty, or a stale value can be mistaken for its failure.'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function probeProvider(): array
    {
        return [
            'isBlockingOrUnknown'        => ['isBlockingOrUnknown'],
            'isMutuallyBlockedOrUnknown' => ['isMutuallyBlockedOrUnknown'],
            'getBlockedSetOrUnknown'     => ['getBlockedSetOrUnknown'],
            'getMutuallyBlockedSetOrUnknown' => ['getMutuallyBlockedSetOrUnknown'],
        ];
    }

    // ── the old methods must be behaviourally unchanged ─────────────

    /**
     * ⚠ The old methods are now one-line delegations, so their BYTES
     * changed. Their BEHAVIOUR must not have — including the fail-open
     * on error, which is left in place deliberately so existing callers
     * are unaffected until each is migrated on purpose.
     */
    public function testTheOldMethodsKeepTheirExactPreviousBehaviour(): void
    {
        // success, blocked
        $this->wpdb->varToReturn = 1;
        $this->wpdb->colToReturn = ['9'];
        $this->assertTrue(PeepSoBlockRepository::isBlocking(7, 9));
        $this->assertTrue(PeepSoBlockRepository::isMutuallyBlocked(7, 9));
        $this->assertSame([9 => true], PeepSoBlockRepository::getBlockedSet(7, [9]));

        // success, not blocked
        $this->wpdb->varToReturn = null;
        $this->wpdb->colToReturn = [];
        $this->assertFalse(PeepSoBlockRepository::isBlocking(7, 9));
        $this->assertFalse(PeepSoBlockRepository::isMutuallyBlocked(7, 9));
        $this->assertSame([], PeepSoBlockRepository::getBlockedSet(7, [9]));

        // ⚠ FAILURE: still fail-OPEN, unchanged, on purpose.
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';
        $this->assertFalse(
            PeepSoBlockRepository::isBlocking(7, 9),
            'Unchanged fail-open: the old contract is preserved so existing callers are not silently altered.'
        );
        $this->assertFalse(PeepSoBlockRepository::isMutuallyBlocked(7, 9));
        $this->assertSame([], PeepSoBlockRepository::getBlockedSet(7, [9]));
    }

    /**
     * The argument guards answer a definite FALSE, not unknown: no query
     * is issued, so there is nothing that could have failed.
     */
    public function testArgumentGuardsAreDefiniteNotUnknown(): void
    {
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        $this->assertFalse(PeepSoBlockRepository::isBlockingOrUnknown(0, 9));
        $this->assertFalse(PeepSoBlockRepository::isBlockingOrUnknown(7, 0));
        $this->assertFalse(PeepSoBlockRepository::isBlockingOrUnknown(7, 7));
        $this->assertFalse(PeepSoBlockRepository::isMutuallyBlockedOrUnknown(7, 7));
        $this->assertSame([], PeepSoBlockRepository::getBlockedSetOrUnknown(7, []));
        $this->assertSame([], PeepSoBlockRepository::getBlockedSetOrUnknown(0, [9]));
        // Self is filtered out, leaving no candidates — still definite.
        $this->assertSame([], PeepSoBlockRepository::getBlockedSetOrUnknown(7, [7]));

        $this->assertSame([], $this->wpdb->prepared, 'A guarded call must not cost a query.');
    }

    /**
     * ⚠ Anti-vacuity. Every "unknown" case above is produced by setting
     * `errorOnQuery`, so a double that simply returned null from
     * everything would satisfy them. This pins that the unknown answers
     * came from a REAL query having been issued, and that the predicate
     * is still the one-directional / either-directional SQL it was.
     */
    /**
     * ⚠⚠ THE EITHER-DIRECTION FOLD, and it is the one behaviour unique
     * to this probe. It selects TWO columns so a row can be folded back
     * to "the other user" whichever way the block runs — which is why
     * it cannot use `get_col(null)` like its sibling, and why it reads
     * `$wpdb->last_result` instead (real `get_results(null)` returns
     * NULL by contract).
     */
    public function testTheMutualSetFoldsBothDirectionsToTheOtherUser(): void
    {
        // row 1: the viewer blocks 9.  row 2: 11 blocks the viewer.
        $this->wpdb->resultsToReturn = [
            ['blk_user_id' => '7',  'blk_blocked_id' => '9'],
            ['blk_user_id' => '11', 'blk_blocked_id' => '7'],
        ];

        $this->assertSame(
            [9 => true, 11 => true],
            PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown(7, [9, 10, 11]),
            'both directions must fold to the OTHER user, never to the viewer'
        );
    }

    /**
     * ⚠ It reads the rows `query()` fetched, so a double that returned
     * rows only from `get_results($sql)` would make this pass for the
     * wrong reason. Asserting the row count proves the read came from
     * `last_result`.
     */
    public function testTheMutualSetReadsTheRowsTheQueryAlreadyFetched(): void
    {
        $this->wpdb->resultsToReturn = [
            ['blk_user_id' => '7', 'blk_blocked_id' => '9'],
        ];

        PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown(7, [9]);

        $this->assertCount(1, $this->wpdb->last_result);
        $this->assertCount(1, $this->wpdb->queried, 'exactly one query, run through query()');
    }

    /** The old method keeps its fail-OPEN contract, unchanged. */
    public function testTheOldMutualSetMethodStillFailsOpen(): void
    {
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        $this->assertSame(
            [],
            PeepSoBlockRepository::getMutuallyBlockedSet(7, [9, 10]),
            'unchanged fail-open, so existing callers are not silently altered'
        );
    }

    public function testTheUnknownAnswersStillCameFromRealQueries(): void
    {
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        PeepSoBlockRepository::isBlockingOrUnknown(7, 9);
        $this->assertCount(1, $this->wpdb->prepared);
        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('WHERE blk_user_id = %d', $sql);
        $this->assertStringContainsString('AND blk_blocked_id = %d', $sql);
        $this->assertSame([7, 9], $this->wpdb->lastArgs());

        PeepSoBlockRepository::isMutuallyBlockedOrUnknown(7, 9);
        $this->assertStringContainsString('OR (blk_user_id = %d', $this->wpdb->lastSqlFlat());
        $this->assertSame([7, 9, 9, 7], $this->wpdb->lastArgs());
    }
    // ── F-331: the id-list readers ──────────────────────────────────

    public function testAFailedIdListReadIsUnknownNotNoBlocks(): void
    {
        $this->wpdb->colToReturn  = ['41'];
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        $this->assertNull(PeepSoBlockRepository::getBlockedIdsOrUnknown(7));
        $this->assertNull(PeepSoBlockRepository::getBlockerIdsOrUnknown(7));
    }

    public function testTheLegacyIdListReadersStillFailOpen(): void
    {
        // Unchanged contract for existing callers (the feed): [] on failure.
        $this->wpdb->colToReturn  = ['41'];
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        $this->assertSame([], PeepSoBlockRepository::getBlockedIds(7));
        $this->assertSame([], PeepSoBlockRepository::getBlockerIds(7));
    }

    public function testASuccessfulIdListReadReturnsThePositiveIdsInBothDirections(): void
    {
        $this->wpdb->colToReturn = ['41', '0', '43'];

        $this->assertSame([41, 43], PeepSoBlockRepository::getBlockedIdsOrUnknown(7, 20));
        $this->assertSame([41, 43], PeepSoBlockRepository::getBlockerIdsOrUnknown(7, 20));
        $this->assertSame([41, 43], PeepSoBlockRepository::getBlockedIds(7, 20));
        $this->assertSame([41, 43], PeepSoBlockRepository::getBlockerIds(7, 20));

        $sql = array_column($this->wpdb->prepared, 'sql');
        $this->assertStringContainsString('WHERE blk_user_id = %d', $sql[0], 'blocked: the viewer is the blocker');
        $this->assertStringContainsString('WHERE blk_blocked_id = %d', $sql[1], 'blockers: the viewer is the blocked');
        $this->assertStringContainsString('WHERE blk_user_id = %d', $sql[2], 'legacy getBlockedIds keeps its direction');
        $this->assertStringContainsString('WHERE blk_blocked_id = %d', $sql[3], 'legacy getBlockerIds keeps its direction');
        $this->assertSame([7, 20], $this->wpdb->prepared[0]['args']);
        $this->assertSame(['', ''], array_slice($this->wpdb->errorSeenAtQuery, 0, 2), 'last_error cleared before each read');
    }

    public function testAnEmptyIdListIsEmptyNotUnknown(): void
    {
        $this->wpdb->colToReturn = [];

        $this->assertSame([], PeepSoBlockRepository::getBlockedIdsOrUnknown(7));
        $this->assertSame([], PeepSoBlockRepository::getBlockerIdsOrUnknown(7));
        $this->assertSame([], PeepSoBlockRepository::getBlockedIdsOrUnknown(0), 'no user, no query');
    }
}
