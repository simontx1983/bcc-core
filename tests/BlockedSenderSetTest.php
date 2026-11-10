<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockedSender;

use BCC\Core\Repositories\PeepSoBlockRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/blocked-sender-capture-wpdb.php';

/**
 * `getBlockedSet` answers exactly one question for bcc-trust #122
 * (hide-on-read): "has THIS viewer blocked these candidates?" — and it
 * must be that question and no other.
 *
 * Two wrong answers are a single edit away, and both compile, run and
 * return a plausible-looking set:
 *
 *   1. `getMutuallyBlockedSet` is one `OR` leg away and returns a
 *      SUPERSET — it would also report "the sender blocked me", which
 *      would hide that sender's message from me. That is the opposite of
 *      the policy recorded on #122.
 *   2. `getBlockedIds` has the right direction but returns the blocker's
 *      whole list under `LIMIT 500`, so past the cap every candidate
 *      reads as not-blocked.
 *
 * Both are pinned below as explicit negative assertions rather than left
 * implied by a passing positive one.
 */
#[CoversClass(PeepSoBlockRepository::class)]
final class BlockedSenderSetTest extends TestCase
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

    public function testTheQueryAsksOnlyWhetherTheBlockerBlocksTheCandidates(): void
    {
        PeepSoBlockRepository::getBlockedSet(7, [11, 12]);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('FROM wp_peepso_blocks', $sql);
        $this->assertStringContainsString('WHERE blk_user_id = %d', $sql);
        $this->assertStringContainsString('AND blk_blocked_id IN (%d,%d)', $sql);
    }

    /**
     * ⚠ MISTAKE 1. Swapping in the mutual query would widen the feature
     * silently; this fails instead.
     */
    public function testTheQueryIsNotTheMutualOne(): void
    {
        PeepSoBlockRepository::getBlockedSet(7, [11, 12]);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringNotContainsString('OR (', $sql);
        $this->assertStringNotContainsString('blk_blocked_id = %d', $sql);
        $this->assertSame(
            1,
            substr_count($sql, 'blk_user_id'),
            'blk_user_id must appear exactly once; twice means both directions are matched.'
        );
    }

    public function testTheBlockerIsBoundFirstThenEveryCandidate(): void
    {
        PeepSoBlockRepository::getBlockedSet(7, [11, 12, 13]);

        $this->assertSame([7, 11, 12, 13], $this->wpdb->lastArgs());
    }

    public function testTheBlockerIsNeverItsOwnCandidate(): void
    {
        PeepSoBlockRepository::getBlockedSet(7, [7, 11]);

        $this->assertSame([7, 11], $this->wpdb->lastArgs());
        $this->assertStringContainsString('IN (%d)', $this->wpdb->lastSqlFlat());
    }

    public function testDuplicateCandidatesCollapseToOnePlaceholder(): void
    {
        PeepSoBlockRepository::getBlockedSet(7, [11, 11, 11]);

        $this->assertSame([7, 11], $this->wpdb->lastArgs());
    }

    /**
     * ⚠ MISTAKE 2, inverted. The cap applies to the CANDIDATE set — the
     * page being rendered — not to the viewer's block list, so a viewer
     * with thousands of blocks still gets a correct answer for the 50
     * conversations in front of them.
     */
    public function testTheCandidateSetIsCappedAtFiveHundred(): void
    {
        $candidates = range(1000, 1000 + 599); // 600 distinct ids
        PeepSoBlockRepository::getBlockedSet(7, $candidates);

        // 1 blocker + 500 candidates, never 1 + 600.
        $this->assertCount(501, $this->wpdb->lastArgs());
        $this->assertSame(500, substr_count($this->wpdb->lastSqlFlat(), '%d') - 1);
    }

    public function testAnEmptyCandidateSetIssuesNoQueryAtAll(): void
    {
        $out = PeepSoBlockRepository::getBlockedSet(7, []);

        $this->assertSame([], $out);
        $this->assertSame([], $this->wpdb->prepared, 'An empty candidate set must not reach the database.');
    }

    public function testAnInvalidBlockerIssuesNoQueryAtAll(): void
    {
        $this->assertSame([], PeepSoBlockRepository::getBlockedSet(0, [11]));
        $this->assertSame([], PeepSoBlockRepository::getBlockedSet(-1, [11]));
        $this->assertSame([], $this->wpdb->prepared);
    }

    public function testTheReturnedSetIsKeyedByTheBlockedCandidate(): void
    {
        $this->wpdb->colToReturn = ['11', '13'];

        $out = PeepSoBlockRepository::getBlockedSet(7, [11, 12, 13]);

        $this->assertSame([11 => true, 13 => true], $out);
        $this->assertArrayNotHasKey(12, $out, 'A candidate the query did not return must be absent, not false.');
    }

    /**
     * Anti-vacuity on the assertions above: with an empty result the set
     * is empty, so a test that merely called the method and asserted "no
     * blocked ids" would also pass against an implementation that never
     * queried anything at all.
     */
    public function testTheEmptyResultCaseStillIssuedARealQuery(): void
    {
        $this->wpdb->colToReturn = [];

        $out = PeepSoBlockRepository::getBlockedSet(7, [11]);

        $this->assertSame([], $out);
        $this->assertCount(
            1,
            $this->wpdb->prepared,
            'The empty answer must come from a query, not from a short-circuit.'
        );
    }
}
