<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests\LeaveRank;

use BCC\Core\PeepSo\PeepSoGroupWriter;
use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * leave() on a duplicated (user, group) pair, decided by the REAL rank.
 *
 * peepso_group_members has no unique (user, group) key, and PeepSo's
 * member_leave() deletes every row of the pair. If an owner's pair also held
 * a stray active row that ranked first, leave() read 'member', passed its
 * owner refusal, and the delete took the owner row with it: the group was
 * left with no owner row. The owner tier in the rank makes leave() see the
 * owner in either row order.
 *
 * Unlike the other writer tests, PeepSoGroupRepository is not faked here;
 * see tests/Stubs/group-leave-rank-stubs.php.
 */
#[CoversClass(PeepSoGroupWriter::class)]
#[CoversMethod(PeepSoGroupRepository::class, 'getMembershipStatusOrFail')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoGroupWriterLeaveRankTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/group-leave-rank-stubs.php';
        $GLOBALS['wpdb']             = new \BccLeaveRankWpdb();
        $GLOBALS['__bcc_lr_rows']    = [];
        $GLOBALS['__bcc_lr_leaves']  = [];
        $GLOBALS['__bcc_lr_actions'] = [];
    }

    /** @return array<string, array{list<string>}> */
    public static function ownerWithAStrayActiveRow(): array
    {
        return [
            'stray member row first'    => [['member', 'member_owner']],
            'owner row first'           => [['member_owner', 'member']],
            'stray moderator row first' => [['member_moderator', 'member_owner']],
        ];
    }

    /** @param list<string> $rows */
    #[DataProvider('ownerWithAStrayActiveRow')]
    public function testLeaveRefusesAnOwnerWithAStrayActiveRow(array $rows): void
    {
        $GLOBALS['__bcc_lr_rows'] = $rows;

        self::assertFalse(PeepSoGroupWriter::leave(7, 42));

        self::assertSame($rows, $GLOBALS['__bcc_lr_rows'], 'both rows kept: the owner row survives');
        self::assertSame([], $GLOBALS['__bcc_lr_leaves'], 'member_leave() never reached');
        self::assertSame([], $GLOBALS['__bcc_lr_actions'], 'no leave hook');
    }

    /** Control: the double does reach member_leave(), so the refusal above is the rank's doing. */
    public function testANonOwnerWithTwoActiveRowsStillLeaves(): void
    {
        $GLOBALS['__bcc_lr_rows'] = ['member', 'member_moderator'];

        self::assertTrue(PeepSoGroupWriter::leave(7, 42));

        self::assertSame([], $GLOBALS['__bcc_lr_rows']);
        self::assertSame([[42, 7]], $GLOBALS['__bcc_lr_leaves']);
        self::assertSame([['peepso_action_group_user_delete', 42, 7]], $GLOBALS['__bcc_lr_actions']);
    }
}
