<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoGroupWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A group ban must survive the banned user's own Leave.
 *
 * PeepSo's member_leave() raw-DELETEs the (user, group) membership row
 * whatever its gm_user_status. leave() used to guard only member_owner, so
 * a banned user could Leave (deleting the banned row) and then Join: join()
 * saw no banned row and let them back in. Every BCC leave route calls this
 * door, so the guard is here, once.
 *
 * Also: a membership read that did not run must refuse both doors, never
 * read as "no row" (join() would flip a ban to membership, leave() would
 * delete it).
 *
 * Same isolation as PeepSoGroupWriterJoinGuardTest (stubs in a subprocess).
 */
#[CoversClass(PeepSoGroupWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoGroupWriterLeaveGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/group-writer-stubs.php';
        $GLOBALS['__bcc_gw_fixture'] = [
            'status'             => null,
            'read_failed'        => false,
            'member_join_calls'  => [],
            'member_leave_calls' => [],
            'count_updates'      => [],
            'actions'            => [],
            'warnings'           => [],
        ];
    }

    public function testABannedUsersLeaveKeepsTheBanRowAndFiresNoLeaveHook(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'banned';

        self::assertTrue(PeepSoGroupWriter::leave(7, 42), 'a banned user is not a member: success, not an error page');
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['member_leave_calls'], 'member_leave() would DELETE the banned row');
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['actions']);
    }

    public function testLeaveThenJoinCannotEraseABan(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'banned';

        PeepSoGroupWriter::leave(7, 42);
        // The row is still 'banned' (leave never deleted it), so join refuses.
        self::assertFalse(PeepSoGroupWriter::join(7, 42));
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['member_leave_calls']);
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['member_join_calls']);
    }

    public function testAMemberStillLeavesAndTheLeaveHookFires(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'member';

        self::assertTrue(PeepSoGroupWriter::leave(7, 42));
        self::assertSame([[42, 7]], $GLOBALS['__bcc_gw_fixture']['member_leave_calls']);
        self::assertSame([['peepso_action_group_user_delete', 42, 7]], $GLOBALS['__bcc_gw_fixture']['actions']);
    }

    public function testAPendingRequestIsStillWithdrawnByLeave(): void
    {
        // Scoped to 'banned': withdrawing a join request (or declining an
        // invite) still deletes the pending row.
        $GLOBALS['__bcc_gw_fixture']['status'] = 'pending_admin';

        self::assertTrue(PeepSoGroupWriter::leave(7, 42));
        self::assertSame([[42, 7]], $GLOBALS['__bcc_gw_fixture']['member_leave_calls']);
    }

    public function testTheOwnerIsStillRefused(): void
    {
        $GLOBALS['__bcc_gw_fixture']['status'] = 'member_owner';

        self::assertFalse(PeepSoGroupWriter::leave(7, 42));
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['member_leave_calls']);
    }

    public function testAFailedMembershipReadRefusesLeaveWithoutDeleting(): void
    {
        $GLOBALS['__bcc_gw_fixture']['read_failed'] = true;

        self::assertFalse(PeepSoGroupWriter::leave(7, 42));
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['member_leave_calls']);
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['actions']);
    }

    public function testAFailedMembershipReadRefusesJoin(): void
    {
        $GLOBALS['__bcc_gw_fixture']['read_failed'] = true;

        self::assertFalse(PeepSoGroupWriter::join(7, 42), 'a failure must not read as "no row" while a ban may be there');
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['member_join_calls']);
        self::assertSame([], $GLOBALS['__bcc_gw_fixture']['actions']);
    }
}
