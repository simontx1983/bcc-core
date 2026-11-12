<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoGroupWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-272: join requests (`pending_admin`) and invitations (`pending_user`).
 *
 * Every transition is one conditional write that names the state it
 * expects, so it is decided by the row's state at WRITE time:
 *   - a pending row is created as pending in one statement, never "insert a
 *     full member, then demote" (that left a full member behind when the
 *     second step failed — an approval bypass);
 *   - a ban that lands between the read and the write is not overwritten
 *     (the write matches 0 rows → conflict);
 *   - a failed write is UNAVAILABLE, never success, and the join hook fires
 *     only after a promotion that really happened.
 *
 * Same isolation as PeepSoGroupWriterLeaveGuardTest (stubs in a subprocess).
 */
#[CoversClass(PeepSoGroupWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoGroupWriterPendingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/group-writer-stubs.php';
        $GLOBALS['__bcc_gw_fixture'] = [
            'status'              => null,
            'read_failed'         => false,
            'writes'              => [],
            'member_join_calls'   => [],
            'member_leave_calls'  => [],
            'member_modify_calls' => [],
            'count_updates'       => [],
            'actions'             => [],
            'warnings'            => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function fx(): array
    {
        return $GLOBALS['__bcc_gw_fixture'];
    }

    private static function assertNothingWritten(): void
    {
        self::assertSame([], self::fx()['writes']);
        self::assertSame([], self::fx()['actions']);
        self::assertSame([], self::fx()['count_updates']);
        self::assertNoPeepSoMemberCalls();
    }

    /** No door may go through PeepSo's member_join (it inserts a FULL member). */
    private static function assertNoPeepSoMemberCalls(): void
    {
        self::assertSame([], self::fx()['member_join_calls']);
        self::assertSame([], self::fx()['member_modify_calls']);
        self::assertSame([], self::fx()['member_leave_calls']);
    }

    // ── happy paths ──────────────────────────────────────────────────

    public function testRequestJoinInsertsAPendingAdminRowInOneStatement(): void
    {
        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::requestJoin(7, 42));
        self::assertSame([['insert', 7, 42, 'pending_admin', null]], self::fx()['writes']);
        self::assertSame([], self::fx()['actions'], 'a request is not a join: no join hook');
        self::assertNoPeepSoMemberCalls();
    }

    public function testInviteInsertsAPendingUserRowRecordingTheInviter(): void
    {
        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::invite(7, 42, 3));
        self::assertSame([['insert', 7, 42, 'pending_user', 3]], self::fx()['writes']);
        self::assertNoPeepSoMemberCalls();
    }

    public function testApprovePromotesRefreshesTheCountAndFiresTheJoinHook(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'pending_admin';

        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::approveJoinRequest(7, 42, 3));
        self::assertSame([['promote', 7, 42, 'pending_admin', 3]], self::fx()['writes']);
        self::assertSame([42], self::fx()['count_updates']);
        self::assertSame([['peepso_action_group_user_join', 42, 7]], self::fx()['actions']);
    }

    public function testAcceptPromotesAndFiresTheJoinHook(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'pending_user';

        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::acceptInvite(7, 42));
        self::assertSame([['promote', 7, 42, 'pending_user', null]], self::fx()['writes']);
        self::assertSame([['peepso_action_group_user_join', 42, 7]], self::fx()['actions']);
    }

    /** @return array<string, array{string, string}> */
    public static function removals(): array
    {
        return [
            'cancel request' => ['cancelJoinRequest', 'pending_admin'],
            'deny request'   => ['denyJoinRequest', 'pending_admin'],
            'revoke invite'  => ['revokeInvite', 'pending_user'],
            'decline invite' => ['declineInvite', 'pending_user'],
        ];
    }

    #[DataProvider('removals')]
    public function testRemovingAPendingRowIsAConditionalDeleteWithoutTheLeaveHook(string $method, string $expected): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = $expected;

        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::$method(7, 42));
        self::assertSame([['delete', 7, 42, $expected]], self::fx()['writes']);
        // The user never was a member; the delete hook means "removed".
        self::assertSame([], self::fx()['actions']);
        self::assertNoPeepSoMemberCalls();
    }

    // ── finding 1: no full-member path, even when the write fails ────

    /** @return array<string, array{string, list<int>}> */
    public static function creations(): array
    {
        return [
            'requestJoin' => ['requestJoin', [7, 42]],
            'invite'      => ['invite', [7, 42, 3]],
        ];
    }

    /** @param list<int> $args */
    #[DataProvider('creations')]
    public function testAFailedInsertIsUnavailableAndNeverLeavesAFullMember(string $method, array $args): void
    {
        $GLOBALS['__bcc_gw_fixture']['insert_result'] = false;

        self::assertSame(PeepSoGroupWriter::OUTCOME_UNAVAILABLE, PeepSoGroupWriter::$method(...$args));
        // The only write attempted was the pending insert: no member_join
        // (a full member) was ever created to be "demoted" afterwards.
        self::assertCount(1, self::fx()['writes']);
        self::assertSame('insert', self::fx()['writes'][0][0]);
        self::assertNoPeepSoMemberCalls();
        self::assertSame([], self::fx()['actions']);
    }

    // ── finding 2: a failed write is never success; hook only after it ─

    /** @return array<string, array{string, list<int>, string, string}> */
    public static function failedWrites(): array
    {
        return [
            'approve, promote fails' => ['approveJoinRequest', [7, 42, 3], 'pending_admin', 'promote_result'],
            'accept, promote fails'  => ['acceptInvite', [7, 42], 'pending_user', 'promote_result'],
            'cancel, delete fails'   => ['cancelJoinRequest', [7, 42], 'pending_admin', 'delete_result'],
            'deny, delete fails'     => ['denyJoinRequest', [7, 42], 'pending_admin', 'delete_result'],
            'revoke, delete fails'   => ['revokeInvite', [7, 42], 'pending_user', 'delete_result'],
            'decline, delete fails'  => ['declineInvite', [7, 42], 'pending_user', 'delete_result'],
        ];
    }

    /** @param list<int> $args */
    #[DataProvider('failedWrites')]
    public function testAFailedWriteIsUnavailableAndFiresNothing(string $method, array $args, string $status, string $resultKey): void
    {
        $GLOBALS['__bcc_gw_fixture']['status']   = $status;
        $GLOBALS['__bcc_gw_fixture'][$resultKey] = false;

        self::assertSame(PeepSoGroupWriter::OUTCOME_UNAVAILABLE, PeepSoGroupWriter::$method(...$args));
        self::assertSame([], self::fx()['actions'], 'no join hook for a promotion that did not happen');
        self::assertSame([], self::fx()['count_updates']);
    }

    // ── finding 3: the state at WRITE time decides (race controls) ────

    /** @return array<string, array{string, list<int>, ?string, string}> */
    public static function racedWrites(): array
    {
        return [
            // read: no row; by the insert, a row exists (e.g. a ban landed)
            'request vs concurrent ban' => ['requestJoin', [7, 42], null, 'insert_result'],
            'invite vs concurrent row'  => ['invite', [7, 42, 3], null, 'insert_result'],
            // read: pending; by the write, the row changed (banned, cancelled, revoked)
            'approve vs concurrent ban'    => ['approveJoinRequest', [7, 42, 3], 'pending_admin', 'promote_result'],
            'accept vs concurrent revoke'  => ['acceptInvite', [7, 42], 'pending_user', 'promote_result'],
            'deny vs concurrent ban'       => ['denyJoinRequest', [7, 42], 'pending_admin', 'delete_result'],
            'decline vs concurrent ban'    => ['declineInvite', [7, 42], 'pending_user', 'delete_result'],
            'cancel vs concurrent approve' => ['cancelJoinRequest', [7, 42], 'pending_admin', 'delete_result'],
            'revoke vs concurrent accept'  => ['revokeInvite', [7, 42], 'pending_user', 'delete_result'],
        ];
    }

    /** @param list<int> $args */
    #[DataProvider('racedWrites')]
    public function testAWriteThatMatchesNoRowIsAConflictNotSuccess(string $method, array $args, ?string $status, string $resultKey): void
    {
        $GLOBALS['__bcc_gw_fixture']['status']   = $status;
        $GLOBALS['__bcc_gw_fixture'][$resultKey] = 0;

        self::assertSame(PeepSoGroupWriter::OUTCOME_CONFLICT, PeepSoGroupWriter::$method(...$args));
        self::assertSame([], self::fx()['actions']);
        self::assertSame([], self::fx()['count_updates']);
        self::assertNoPeepSoMemberCalls();
    }

    // ── a ban seen at read time is never written ─────────────────────

    /** @return array<string, array{string, list<int>}> */
    public static function everyTransition(): array
    {
        return [
            'requestJoin'        => ['requestJoin', [7, 42]],
            'cancelJoinRequest'  => ['cancelJoinRequest', [7, 42]],
            'approveJoinRequest' => ['approveJoinRequest', [7, 42, 3]],
            'denyJoinRequest'    => ['denyJoinRequest', [7, 42]],
            'invite'             => ['invite', [7, 42, 3]],
            'revokeInvite'       => ['revokeInvite', [7, 42]],
            'acceptInvite'       => ['acceptInvite', [7, 42]],
            'declineInvite'      => ['declineInvite', [7, 42]],
        ];
    }

    /** @param list<int> $args */
    #[DataProvider('everyTransition')]
    public function testABannedRowIsRefusedAndNeverWritten(string $method, array $args): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'banned';

        self::assertSame(PeepSoGroupWriter::OUTCOME_REFUSED, PeepSoGroupWriter::$method(...$args));
        self::assertNothingWritten();
    }

    /** @param list<int> $args */
    #[DataProvider('everyTransition')]
    public function testAFailedMembershipReadIsUnavailableAndNeverWrites(string $method, array $args): void
    {
        $GLOBALS['__bcc_gw_fixture']['status']      = 'banned';
        $GLOBALS['__bcc_gw_fixture']['read_failed'] = true;

        self::assertSame(PeepSoGroupWriter::OUTCOME_UNAVAILABLE, PeepSoGroupWriter::$method(...$args));
        self::assertNothingWritten();
    }

    // ── wrong state at read time = conflict, nothing written ─────────

    /** @return array<string, array{string, list<int>, ?string}> */
    public static function wrongStates(): array
    {
        return [
            'request while member'        => ['requestJoin', [7, 42], 'member'],
            'request while invited'       => ['requestJoin', [7, 42], 'pending_user'],
            'request twice'               => ['requestJoin', [7, 42], 'pending_admin'],
            'invite a member'             => ['invite', [7, 42, 3], 'member_manager'],
            'invite a requester'          => ['invite', [7, 42, 3], 'pending_admin'],
            'invite twice'                => ['invite', [7, 42, 3], 'pending_user'],
            'approve a cancelled request' => ['approveJoinRequest', [7, 42, 3], null],
            'approve an invitee'          => ['approveJoinRequest', [7, 42, 3], 'pending_user'],
            'deny a member'               => ['denyJoinRequest', [7, 42], 'member'],
            'cancel an invite as request' => ['cancelJoinRequest', [7, 42], 'pending_user'],
            'accept a revoked invite'     => ['acceptInvite', [7, 42], null],
            'accept as requester'         => ['acceptInvite', [7, 42], 'pending_admin'],
            'revoke a member'             => ['revokeInvite', [7, 42], 'member'],
            'decline the owner row'       => ['declineInvite', [7, 42], 'member_owner'],
        ];
    }

    /** @param list<int> $args */
    #[DataProvider('wrongStates')]
    public function testATransitionFromTheWrongStateIsAConflictAndWritesNothing(string $method, array $args, ?string $status): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = $status;

        self::assertSame(PeepSoGroupWriter::OUTCOME_CONFLICT, PeepSoGroupWriter::$method(...$args));
        self::assertNothingWritten();
    }

    // ── the (user, group) pair lock (no unique index on the pair) ─────

    /** @param list<int> $args */
    #[DataProvider('everyTransition')]
    public function testEveryDoorWritesOnlyInsideThePairLockAndReleasesIt(string $method, array $args): void
    {
        // A state each door accepts, so every door reaches its write.
        $GLOBALS['__bcc_gw_fixture']['status'] = match ($method) {
            'requestJoin', 'invite'                                   => null,
            'cancelJoinRequest', 'approveJoinRequest', 'denyJoinRequest' => 'pending_admin',
            default                                                   => 'pending_user',
        };

        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::$method(...$args));
        self::assertSame([true], self::fx()['locked_writes'], 'the write ran while the pair lock was held');
        self::assertSame(
            [['acquire', 'bcc_group_member_42_7'], ['release', 'bcc_group_member_42_7']],
            self::fx()['lock_calls']
        );
        self::assertSame([], self::fx()['held'] ?? [], 'released afterwards');
    }

    /** @param list<int> $args */
    #[DataProvider('everyTransition')]
    public function testABusyPairLockIsUnavailableAndWritesNothing(string $method, array $args): void
    {
        $GLOBALS['__bcc_gw_fixture']['status']    = 'pending_admin';
        $GLOBALS['__bcc_gw_fixture']['lock_busy'] = true;

        self::assertSame(PeepSoGroupWriter::OUTCOME_UNAVAILABLE, PeepSoGroupWriter::$method(...$args));
        self::assertNothingWritten();
    }

    /**
     * join() inserts for the same pair (MyGroups, Halls, holder and delegator
     * groups, the reconcile sweep) — it must take the SAME lock, or a sweep
     * auto-join racing a join request could leave two rows for the pair.
     */
    public function testJoinTakesTheSamePairLockAndFailsClosedWhenBusy(): void
    {
        self::assertTrue(PeepSoGroupWriter::join(7, 42));
        self::assertSame([true], self::fx()['locked_writes']);
        self::assertSame(['acquire', 'bcc_group_member_42_7'], self::fx()['lock_calls'][0]);

        $GLOBALS['__bcc_gw_fixture'] = ['lock_busy' => true, 'member_join_calls' => [], 'actions' => []];
        self::assertFalse(PeepSoGroupWriter::join(7, 42));
        self::assertSame([], self::fx()['member_join_calls']);
        self::assertSame([], self::fx()['actions']);
    }

    public function testLeaveTakesTheSamePairLockAndFailsClosedWhenBusy(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'member';
        self::assertTrue(PeepSoGroupWriter::leave(7, 42));
        self::assertSame([true], self::fx()['locked_writes']);

        $GLOBALS['__bcc_gw_fixture'] = ['status' => 'member', 'lock_busy' => true, 'member_leave_calls' => [], 'actions' => []];
        self::assertFalse(PeepSoGroupWriter::leave(7, 42));
        self::assertSame([], self::fx()['member_leave_calls']);
        self::assertSame([], self::fx()['actions']);
    }

    public function testInvitingSomeoneWhoBlocksInvitesIsRefused(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'block_invites';

        self::assertSame(PeepSoGroupWriter::OUTCOME_REFUSED, PeepSoGroupWriter::invite(7, 42, 3));
        self::assertNothingWritten();
    }

    public function testInvalidIdsAreUnavailable(): void
    {
        self::assertSame(PeepSoGroupWriter::OUTCOME_UNAVAILABLE, PeepSoGroupWriter::requestJoin(0, 42));
        self::assertSame(PeepSoGroupWriter::OUTCOME_UNAVAILABLE, PeepSoGroupWriter::invite(7, 42, 0));
        self::assertSame(PeepSoGroupWriter::OUTCOME_UNAVAILABLE, PeepSoGroupWriter::acceptInvite(7, -1));
        self::assertNothingWritten();
    }
}
