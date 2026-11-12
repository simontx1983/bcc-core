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
 * Every transition acts only on the row state it expects. That keeps a
 * `banned` row untouchable through these doors (a ban must survive them all,
 * as it survives leave()) and makes a raced transition a conflict, never a
 * silent overwrite. A membership read that did not run refuses: it must not
 * read as "no row" while a banned row may be there.
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
        self::assertSame([], self::fx()['member_join_calls']);
        self::assertSame([], self::fx()['member_leave_calls']);
        self::assertSame([], self::fx()['member_modify_calls']);
        self::assertSame([], self::fx()['actions']);
    }

    // ── happy paths ──────────────────────────────────────────────────

    public function testRequestJoinCreatesAPendingAdminRow(): void
    {
        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::requestJoin(7, 42));
        self::assertSame([[42, 7]], self::fx()['member_join_calls']);
        self::assertSame([[42, 7, 'pending_admin', null, null]], self::fx()['member_modify_calls']);
        self::assertSame([], self::fx()['actions'], 'a request is not a join: no join hook');
    }

    public function testInviteCreatesAPendingUserRowRecordingTheInviter(): void
    {
        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::invite(7, 42, 3));
        self::assertSame([[42, 7]], self::fx()['member_join_calls']);
        self::assertSame([[42, 7, 'pending_user', 3, null]], self::fx()['member_modify_calls']);
        self::assertSame([], self::fx()['actions']);
    }

    public function testApproveMakesTheRequesterAMemberAndFiresTheJoinHook(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'pending_admin';

        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::approveJoinRequest(7, 42, 3));
        self::assertSame([[42, 7, 'member', null, 3]], self::fx()['member_modify_calls']);
        self::assertSame([['peepso_action_group_user_join', 42, 7]], self::fx()['actions']);
        self::assertSame([], self::fx()['member_join_calls']);
    }

    public function testAcceptMakesTheInviteeAMemberAndFiresTheJoinHook(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'pending_user';

        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::acceptInvite(7, 42));
        self::assertSame([[42, 7, 'member', null, null]], self::fx()['member_modify_calls']);
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
    public function testRemovingAPendingRowDeletesItWithoutTheLeaveHook(string $method, string $expected): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = $expected;

        self::assertSame(PeepSoGroupWriter::OUTCOME_DONE, PeepSoGroupWriter::$method(7, 42));
        self::assertSame([[42, 7]], self::fx()['member_leave_calls']);
        // The user never was a member; the delete hook means "removed".
        self::assertSame([], self::fx()['actions']);
    }

    // ── a ban is never touched ───────────────────────────────────────

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

    // ── wrong state = conflict, nothing written ──────────────────────

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
