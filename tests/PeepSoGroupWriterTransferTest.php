<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoGroupWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Rank Phase 7 (§21.2) — ownership-transfer writer.
 *
 * Pins the 5-step PeepSo owner-change sequence (promote receiver →
 * owner_id switch → demote old owner → manager action → owner action)
 * and the defense-in-depth preconditions: group exists, from-user is
 * the current owner on BOTH books, receiver already holds an ACTIVE
 * membership row (the writer never inserts one — §C2 single-graph).
 *
 * BCC's policy gates (capability / cap / cooldown) live in bcc-trust
 * and are exercised there; this suite covers only the sanctioned write
 * path. Isolation mirrors PeepSoGroupWriterJoinGuardTest — subprocess +
 * FQN fakes, zero DB / WordPress dependency.
 */
#[CoversClass(PeepSoGroupWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoGroupWriterTransferTest extends TestCase
{
    private const GROUP = 42;
    private const OWNER = 7;
    private const RECEIVER = 9;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/group-transfer-stubs.php';
        $GLOBALS['__bcc_gt_fixture'] = [
            'group_id'     => self::GROUP,
            'owner_id'     => self::OWNER,
            'statuses'     => [
                self::OWNER    => 'member_owner',
                self::RECEIVER => 'member',
            ],
            'modify_ok'      => [],
            'update_applies' => true,
            'modify_calls'   => [],
            'updates'        => [],
            'actions'        => [],
            'errors'         => [],
        ];
    }

    public function testHappyPathRunsTheFiveStepSequenceInPeepSoOrder(): void
    {
        self::assertTrue(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::RECEIVER));

        // Promote receiver first, demote old owner second — exact AJAX order.
        self::assertSame(
            [
                [self::GROUP, self::RECEIVER, 'member_owner'],
                [self::GROUP, self::OWNER, 'member_manager'],
            ],
            $GLOBALS['__bcc_gt_fixture']['modify_calls']
        );

        // owner_id pointer switch between the two membership writes.
        self::assertSame([['owner_id' => self::RECEIVER]], $GLOBALS['__bcc_gt_fixture']['updates']);

        // Both role-change hooks, manager (old owner) before owner (receiver).
        self::assertSame(
            [
                ['peepso_action_group_user_role_change_manager', self::GROUP, self::OWNER],
                ['peepso_action_group_user_role_change_owner', self::GROUP, self::RECEIVER],
            ],
            $GLOBALS['__bcc_gt_fixture']['actions']
        );

        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['errors']);
    }

    public function testInvalidIdsAndSelfTransferRefuseBeforeAnyLookup(): void
    {
        self::assertFalse(PeepSoGroupWriter::transferOwnership(0, self::OWNER, self::RECEIVER));
        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, -1, self::RECEIVER));
        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, 0));
        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::OWNER));
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['modify_calls']);
    }

    public function testMissingGroupRefusesWithoutWrites(): void
    {
        $GLOBALS['__bcc_gt_fixture']['group_id'] = 0;

        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::RECEIVER));
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['modify_calls']);
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['updates']);
        self::assertCount(1, $GLOBALS['__bcc_gt_fixture']['errors']);
    }

    public function testFromUserMustBeCurrentOwnerOnTheGroupModel(): void
    {
        $GLOBALS['__bcc_gt_fixture']['owner_id'] = 999;

        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::RECEIVER));
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['modify_calls']);
    }

    public function testFromUserMustHoldTheMemberOwnerRow(): void
    {
        // owner_id says OWNER but the membership row drifted — refuse.
        $GLOBALS['__bcc_gt_fixture']['statuses'][self::OWNER] = 'member_manager';

        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::RECEIVER));
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['modify_calls']);
    }

    public function testReceiverWithoutActiveMembershipIsRefusedNeverInserted(): void
    {
        foreach ([null, 'pending_admin', 'banned', 'block_invites'] as $status) {
            $GLOBALS['__bcc_gt_fixture']['statuses'][self::RECEIVER] = $status;
            self::assertFalse(
                PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::RECEIVER),
                'status ' . var_export($status, true) . ' must refuse'
            );
        }
        // No membership write of ANY kind happened — §C2: the writer
        // must never create a membership row as a transfer side effect.
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['modify_calls']);
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['updates']);
    }

    public function testReceiverPromoteFailureAbortsBeforeOwnerSwitch(): void
    {
        $GLOBALS['__bcc_gt_fixture']['modify_ok'][self::RECEIVER] = false;

        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::RECEIVER));
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['updates']);
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['actions']);
        self::assertCount(1, $GLOBALS['__bcc_gt_fixture']['errors']);
    }

    public function testOwnerPointerVerificationFailsFastBeforeDemoteAndHooks(): void
    {
        // PeepSoGroup::update() is void and swallows wp_update_post
        // failure — simulate the pointer NOT moving. Step 3b's re-read
        // must catch it and fail fast BEFORE the old-owner demote and
        // both role-change hooks.
        $GLOBALS['__bcc_gt_fixture']['update_applies'] = false;

        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::RECEIVER));

        // The promote landed and the pointer write was attempted…
        self::assertSame(
            [[self::GROUP, self::RECEIVER, 'member_owner']],
            $GLOBALS['__bcc_gt_fixture']['modify_calls']
        );
        self::assertSame([['owner_id' => self::RECEIVER]], $GLOBALS['__bcc_gt_fixture']['updates']);

        // …but the demote never ran, no hooks fired, and the PARTIAL
        // state is loud.
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['actions']);
        self::assertCount(1, $GLOBALS['__bcc_gt_fixture']['errors']);
    }

    public function testOldOwnerDemoteFailureReturnsFalseAndFiresNoHooks(): void
    {
        $GLOBALS['__bcc_gt_fixture']['modify_ok'][self::OWNER] = false;

        self::assertFalse(PeepSoGroupWriter::transferOwnership(self::GROUP, self::OWNER, self::RECEIVER));
        // Partial state is loud: the promote + pointer switch happened,
        // the failure is logged, and no subscriber hooks fired.
        self::assertSame([['owner_id' => self::RECEIVER]], $GLOBALS['__bcc_gt_fixture']['updates']);
        self::assertSame([], $GLOBALS['__bcc_gt_fixture']['actions']);
        self::assertCount(1, $GLOBALS['__bcc_gt_fixture']['errors']);
    }
}
