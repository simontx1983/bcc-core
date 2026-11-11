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

        $out = PeepSoBlockRepository::$method(7, $method === 'getBlockedSetOrUnknown' ? [9] : 9);

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

        PeepSoBlockRepository::$method(7, $method === 'getBlockedSetOrUnknown' ? [9] : 9);

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
}
