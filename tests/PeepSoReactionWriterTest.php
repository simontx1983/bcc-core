<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoReactionWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Missing-activity guard for the reaction writer.
 *
 * PeepSoReactionsModel::init() on a missing/deleted activity leaves
 * act_external_id null (PeepSo's get_activity() returns NULL), after
 * which user_reaction_set() would still INSERT an orphan
 * peepso_reactions row keyed at the dead act_id — and return TRUE
 * unconditionally. The writer must gate on the data init() fetched and
 * refuse BEFORE the write. The headline assertion here is therefore
 * "missing activity → false AND zero user_reaction_set calls".
 *
 * ## Isolation
 * Runs in its own subprocess; setUp() pulls in
 * tests/Stubs/reaction-writer-stubs.php which provides a recording
 * PeepSoReactionsModel fake plus Logger / DegradationMetrics at their
 * FQNs, so every assertion is on the writer's real control flow with
 * zero DB or WordPress dependency.
 */
#[CoversClass(PeepSoReactionWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoReactionWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/reaction-writer-stubs.php';
        $GLOBALS['__bcc_rw_fixture'] = [
            'external_id' => null,
            'init_calls'  => [],
            'set_calls'   => [],
            'reset_calls' => 0,
            'constructed' => 0,
        ];
    }

    // ── The headline: missing activity must not write ───────────────────

    public function testMissingActivityRefusesSetWithoutWriting(): void
    {
        $GLOBALS['__bcc_rw_fixture']['external_id'] = null;

        self::assertFalse(PeepSoReactionWriter::setReaction(123, 4));

        // init() ran (that's how absence is detected) but no reaction row
        // was written — the orphan-insert guard.
        self::assertSame([123], $GLOBALS['__bcc_rw_fixture']['init_calls']);
        self::assertSame([], $GLOBALS['__bcc_rw_fixture']['set_calls']);
    }

    public function testMissingActivityRefusesRemoveWithoutWriting(): void
    {
        $GLOBALS['__bcc_rw_fixture']['external_id'] = null;

        self::assertFalse(PeepSoReactionWriter::removeReaction(123));

        self::assertSame([123], $GLOBALS['__bcc_rw_fixture']['init_calls']);
        self::assertSame(0, $GLOBALS['__bcc_rw_fixture']['reset_calls']);
    }

    // ── Present activity: write goes through ────────────────────────────

    public function testPresentActivitySetsReaction(): void
    {
        $GLOBALS['__bcc_rw_fixture']['external_id'] = 555;

        self::assertTrue(PeepSoReactionWriter::setReaction(123, 4));

        self::assertSame([123], $GLOBALS['__bcc_rw_fixture']['init_calls']);
        self::assertSame([4], $GLOBALS['__bcc_rw_fixture']['set_calls']);
    }

    public function testPresentActivityRemovesReaction(): void
    {
        $GLOBALS['__bcc_rw_fixture']['external_id'] = 555;

        self::assertTrue(PeepSoReactionWriter::removeReaction(123));

        self::assertSame([123], $GLOBALS['__bcc_rw_fixture']['init_calls']);
        self::assertSame(1, $GLOBALS['__bcc_rw_fixture']['reset_calls']);
    }

    // ── Invalid ids short-circuit before the model exists ───────────────

    public function testInvalidIdsRejectedWithoutConstructingModel(): void
    {
        self::assertFalse(PeepSoReactionWriter::setReaction(0, 4));
        self::assertFalse(PeepSoReactionWriter::setReaction(123, 0));
        self::assertFalse(PeepSoReactionWriter::removeReaction(-1));

        self::assertSame(0, $GLOBALS['__bcc_rw_fixture']['constructed']);
    }
}
