<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoFollowWriter;
use BCC\Core\Repositories\PeepSoBlockRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-30: a block prevents a follow, at the single follow write point.
 *
 * Before, someone you had blocked (or who had blocked you) could still
 * follow you: PeepSoFollowWriter::follow() never looked at blocks, and every
 * follow path (watch, the claim-time migration) goes through it. It now
 * refuses (returns 0, PeepSo never touched) on a block in EITHER direction,
 * and on a block state it cannot read (fail closed).
 */
#[CoversClass(PeepSoFollowWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoFollowWriterBlockGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/follow-writer-stubs.php';
        PeepSoBlockRepository::$verdict = false;
        PeepSoBlockRepository::$asked   = [];
        \PeepSoUserFollower::$constructed = [];

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }
            public function get_var(string $sql): string
            {
                return '77'; // the uf_id read back after the upsert
            }
        };
    }

    public function testAnUnblockedPairFollows(): void
    {
        self::assertSame(77, PeepSoFollowWriter::follow(10, 20), 'control');
        self::assertSame([[20, 10, true]], \PeepSoUserFollower::$constructed);
        self::assertSame([[10, 20]], PeepSoBlockRepository::$asked);
    }

    public function testABlockInEitherDirectionRefusesWithoutTouchingPeepSo(): void
    {
        PeepSoBlockRepository::$verdict = true;

        self::assertSame(0, PeepSoFollowWriter::follow(10, 20));
        self::assertSame([], \PeepSoUserFollower::$constructed, 'no follow row is written');
    }

    public function testAnUnreadableBlockStateFailsClosed(): void
    {
        PeepSoBlockRepository::$verdict = null;

        self::assertSame(0, PeepSoFollowWriter::follow(10, 20));
        self::assertSame([], \PeepSoUserFollower::$constructed);
    }
}
