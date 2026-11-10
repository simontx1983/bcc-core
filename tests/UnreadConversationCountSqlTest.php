<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockedSender;

use BCC\Core\Repositories\PeepSoMessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/blocked-sender-capture-wpdb.php';

/**
 * The global header badge — the count of CONVERSATIONS holding at least
 * one unread message.
 *
 * bcc-trust #122 had to replace the `PeepSoMessageRecipients::get_unread_messages_count`
 * wrap with BCC-owned SQL, because that helper exposes no filter at all.
 * Two things therefore need pinning that no other test covers:
 *
 *   1. the two exclusions the wrap used to provide for free — muted
 *      conversations, and conversations carrying a participant row for a
 *      user who no longer exists. `docs/api-contract-v1.md` documents
 *      both as contract for `GET /me/messages/unread-count`, so losing
 *      either is a contract break with no other symptom;
 *   2. that the blocked-sender exclusion filters ROWS BEFORE `GROUP BY`.
 *      The count is conversation-level, so applying it after the grouping
 *      would make a conversation holding one unread message from a
 *      blocked sender and one from anybody else vanish from the badge
 *      entirely — a user-visible bug that an "excluded a blocked sender"
 *      assertion would not notice.
 *
 * ⚠ These cases run in separate processes because they eval-define a
 * global-namespace `PeepSoMessageRecipients` to get past the method's
 * `class_exists` guard, and a leaked stub would change what a sibling
 * suite sees. Mitigation copied from PeepSoMessageWriterReadinessTest.
 *
 * ⚠ The `class_exists` guard itself and its `peepso_absence` degradation
 * metric are deliberately NOT exercised here. That branch is pre-existing
 * behaviour which #122 does not change, and reaching it would drag the
 * object-cache stubs into a test about SQL composition.
 */
#[CoversClass(PeepSoMessageRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UnreadConversationCountSqlTest extends TestCase
{
    private CaptureWpdb $wpdb;

    protected function setUp(): void
    {
        if (!class_exists('PeepSoMessageRecipients', false)) {
            eval('class PeepSoMessageRecipients {}');
        }
        $this->wpdb      = new CaptureWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testTheBlockedSenderExclusionIsPresent(): void
    {
        PeepSoMessageRepository::getUnreadConversationCountForUser(7);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('JOIN wp_peepso_blocks b ON b.blk_blocked_id = p.post_author', $sql);
        $this->assertStringContainsString('AND b.blk_user_id = %d', $sql);
    }

    public function testPeepSosMutedExclusionIsPreserved(): void
    {
        PeepSoMessageRepository::getUnreadConversationCountForUser(7);

        $this->assertStringContainsString(
            'mpart_muted = 1 AND mpart_user_id = %d',
            $this->wpdb->lastSqlFlat()
        );
    }

    public function testPeepSosDeletedParticipantExclusionIsPreserved(): void
    {
        PeepSoMessageRepository::getUnreadConversationCountForUser(7);

        $this->assertStringContainsString(
            'WHERE mpart_user_id NOT IN (SELECT ID FROM wp_users)',
            $this->wpdb->lastSqlFlat()
        );
    }

    public function testTheExclusionFiltersRowsBeforeGrouping(): void
    {
        PeepSoMessageRepository::getUnreadConversationCountForUser(7);

        $sql       = $this->wpdb->lastSqlFlat();
        $notExists = strpos($sql, 'NOT EXISTS');
        $groupBy   = strpos($sql, 'GROUP BY');

        $this->assertIsInt($notExists);
        $this->assertIsInt($groupBy);
        $this->assertLessThan(
            $groupBy,
            $notExists,
            'The blocked-sender exclusion must precede GROUP BY, or a mixed conversation drops out of the badge.'
        );
    }

    public function testTheOuterCountWrapsTheGroupedSubquery(): void
    {
        PeepSoMessageRepository::getUnreadConversationCountForUser(7);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('SELECT COUNT(*) FROM (', $sql);
        $this->assertStringContainsString('GROUP BY r.mrec_parent_id ) g', $sql);
    }

    public function testTheViewerIsBoundExactlyThreeTimes(): void
    {
        PeepSoMessageRepository::getUnreadConversationCountForUser(7);

        $this->assertSame([7, 7, 7], $this->wpdb->lastArgs());
    }

    public function testTheOwnRowsAndUnreadPredicatesSurvive(): void
    {
        PeepSoMessageRepository::getUnreadConversationCountForUser(7);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('r.mrec_user_id = %d', $sql);
        $this->assertStringContainsString('r.mrec_viewed = 0', $sql);
        $this->assertStringContainsString('r.mrec_deleted = 0', $sql);
    }

    public function testAnInvalidViewerIssuesNoQuery(): void
    {
        $this->assertSame(0, PeepSoMessageRepository::getUnreadConversationCountForUser(0));
        $this->assertSame([], $this->wpdb->prepared);
    }
}
