<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockedSender;

use BCC\Core\Repositories\PeepSoMessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/blocked-sender-capture-wpdb.php';

/**
 * F-300 — a failed DM read must be distinguishable from "no messages".
 *
 * `findConversationsForUser()` and `findMessagesInConversation()` ended in
 * `get_results(...) ?: []` / `get_col(...) ?: []`, so a failed read was an
 * empty inbox ("No conversations yet") or an empty thread, and bcc-trust
 * went on to mark that thread read. The `...OrUnknown` reads report the
 * failure as NULL; the old methods keep their exact contract by
 * delegating (`?? []`), because bcc-trust CI resolves this repo's default
 * branch (same additive rule as BlockReadFailureSignalTest).
 */
#[CoversClass(PeepSoMessageRepository::class)]
final class MessageReadFailureSignalTest extends TestCase
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

    public function testAFailedInboxReadIsUnknownNotEmpty(): void
    {
        $this->wpdb->errorOnQuery = 'MySQL server has gone away';

        $this->assertNull(PeepSoMessageRepository::findConversationsForUserOrUnknown(7, 20, 0));
        $this->assertSame([], PeepSoMessageRepository::findConversationsForUser(7, 20, 0), 'the old contract is unchanged');
    }

    public function testAFailedThreadReadIsUnknownNotEmpty(): void
    {
        $this->wpdb->errorOnQuery = 'Lock wait timeout exceeded';

        $this->assertNull(PeepSoMessageRepository::findMessagesInConversationOrUnknown(500, 7, 20, 0));
        $this->assertSame([], PeepSoMessageRepository::findMessagesInConversation(500, 7, 20, 0));
    }

    /**
     * ⚠ query() can return false WITHOUT last_error (wpdb's pre-flush
     * bail), leaving the previous query's rows in last_result. Those
     * stale rows must not be served as this inbox.
     */
    public function testAFalseQueryWithNoErrorIsStillUnknownAndStaleRowsAreNotServed(): void
    {
        $this->wpdb->last_result = [(object) ['root_msg_id' => 999]];
        $this->wpdb->queryReturn = false;

        $this->assertNull(PeepSoMessageRepository::findConversationsForUserOrUnknown(7, 20, 0));
        $this->assertNull(PeepSoMessageRepository::findMessagesInConversationOrUnknown(500, 7, 20, 0));
    }

    public function testAGenuinelyEmptyInboxIsAnEmptyListNotUnknown(): void
    {
        $this->wpdb->resultsToReturn = [];
        $this->wpdb->queryReturn     = 0; // a SELECT's row count: 0 is success

        $this->assertSame([], PeepSoMessageRepository::findConversationsForUserOrUnknown(7, 20, 0));
        $this->assertSame([], PeepSoMessageRepository::findMessagesInConversationOrUnknown(500, 7, 20, 0));
    }

    public function testASuccessfulInboxReadStillShapesItsRows(): void
    {
        $this->wpdb->resultsToReturn = [[
            'root_msg_id'        => '500',
            'is_group'           => '0',
            'last_activity'      => '2026-11-12 10:00:00',
            'last_msg_id'        => '503',
            'last_msg_author_id' => '9',
            'last_msg_content'   => 'hi',
            'last_msg_posted_at' => '2026-11-12 10:00:00',
        ]];

        $rows = PeepSoMessageRepository::findConversationsForUserOrUnknown(7, 20, 0);

        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(500, $rows[0]['root_msg_id']);
        $this->assertSame(9, $rows[0]['last_msg_author_id']);
        $this->assertFalse($rows[0]['is_group']);
    }

    public function testASuccessfulThreadReadStillShapesItsRows(): void
    {
        // The double hands the same rows to both reads: step 1 takes the
        // ids from `mrec_msg_id`, step 2 shapes the post columns.
        $this->wpdb->resultsToReturn = [[
            'mrec_msg_id' => '503',
            'id'          => '503',
            'author_id'   => '9',
            'body'        => 'hi',
            'posted_at'   => '2026-11-12 10:00:00',
            'post_type'   => 'peepso-message',
        ]];

        $rows = PeepSoMessageRepository::findMessagesInConversationOrUnknown(500, 7, 20, 0);

        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(503, $rows[0]['id']);
        $this->assertSame('hi', $rows[0]['body']);
        $this->assertCount(2, $this->wpdb->queried, 'id page, then the posts');
        $this->assertStringContainsString('IN (%d)', $this->wpdb->lastSql(), 'the posts read is bounded to the id page');
    }
}
