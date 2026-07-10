<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoGroupWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Banned-row guard for the trusted join door — [audit M — group-rejoin]
 * follow-up.
 *
 * PeepSo's member_join() falls through to member_modify('member') on ANY
 * existing membership row — including gm_user_status='banned' — so before
 * the guard, every caller of PeepSoGroupWriter::join (REST joins, the
 * auto-join reconcile sweep) would silently flip a group admin's ban back
 * to full membership. The guard refuses centrally: a banned row means
 * join() returns false and PeepSo is never touched.
 *
 * ## Isolation
 * Runs in its own subprocess; setUp() pulls in
 * tests/Stubs/group-writer-stubs.php which fakes PeepSoGroupRepository +
 * Logger at their FQNs and provides recording PeepSoGroupUser /
 * PeepSoGroupUsers / do_action fakes, so every assertion is on the
 * writer's real control flow with zero DB or WordPress dependency.
 */
#[CoversClass(PeepSoGroupWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoGroupWriterJoinGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/group-writer-stubs.php';
        $GLOBALS['__bcc_gw_fixture'] = [
            'status'            => null,
            'member_join_calls' => [],
            'count_updates'     => [],
            'actions'           => [],
            'warnings'          => [],
        ];
    }

    public function testBannedRowRefusesJoinWithoutTouchingPeepSo(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'banned';

        self::assertFalse(PeepSoGroupWriter::join(7, 42));

        // The ban must stick: no membership write, no counter refresh,
        // no downstream join hook.
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['member_join_calls']);
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['count_updates']);
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['actions']);
        self::assertCount(1, $GLOBALS['__bcc_gw_fixture']['warnings']);
    }

    public function testNoExistingRowJoinsAndFiresJoinHook(): void
    {
        self::assertTrue(PeepSoGroupWriter::join(7, 42));

        // PeepSoGroupUser is constructed (groupId, userId) — pinned so an
        // accidental argument swap fails loudly here.
        self::assertSame([[42, 7]], $GLOBALS['__bcc_gw_fixture']['member_join_calls']);
        self::assertSame([42], $GLOBALS['__bcc_gw_fixture']['count_updates']);
        self::assertSame(
            [['peepso_action_group_user_join', 42, 7]],
            $GLOBALS['__bcc_gw_fixture']['actions']
        );
    }

    public function testExistingMemberRowRemainsIdempotentSuccess(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'member';

        self::assertTrue(PeepSoGroupWriter::join(7, 42));
        self::assertSame([[42, 7]], $GLOBALS['__bcc_gw_fixture']['member_join_calls']);
    }

    public function testPendingRowStillUpgradesThroughMemberJoin(): void
    {
        // pending_admin arises only via PeepSo's own UI flow; the guard is
        // scoped to 'banned' and must not widen silently.
        $GLOBALS['__bcc_gw_fixture']['status'] = 'pending_admin';

        self::assertTrue(PeepSoGroupWriter::join(7, 42));
        self::assertSame([[42, 7]], $GLOBALS['__bcc_gw_fixture']['member_join_calls']);
    }

    public function testInvalidIdsAreRefusedBeforeAnyLookup(): void
    {
        self::assertFalse(PeepSoGroupWriter::join(0, 42));
        self::assertFalse(PeepSoGroupWriter::join(7, -1));
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['member_join_calls']);
    }
}
