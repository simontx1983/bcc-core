<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockedSender;

use BCC\Core\Repositories\PeepSoMessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/blocked-sender-capture-wpdb.php';

/**
 * `viewerReceivedMessage` exists to close an authorization hole found by
 * review in bcc-trust #381: its tap-to-reveal route gated on
 * PARTICIPATION in a conversation, which is not the same as having been
 * SENT a particular message.
 *
 * ⚠⚠ THE PREDICATE MUST MATCH `findMessagesInConversation` EXACTLY,
 * including the ABSENCE of an `mrec_deleted` filter. The invariant is "a
 * reveal returns no more than the thread read would have returned for
 * this viewer", and that cuts both ways:
 *
 *   - too loose (no recipient check at all) discloses messages PeepSo
 *     never delivered — the bug this method fixes;
 *   - too strict (adding `AND mrec_deleted = 0`) makes a reveal stricter
 *     than the thread, producing a message the viewer can read in the
 *     thread but cannot reveal. `delete_from_inbox` soft-sets that flag
 *     across a whole conversation and the thread read does not filter
 *     it.
 *
 * So the absence of that filter is asserted as deliberately as its
 * presence would be. A capturing `$wpdb` is the right instrument because
 * what matters is the WHERE clause, not the row that comes back.
 */
#[CoversClass(PeepSoMessageRepository::class)]
final class ViewerReceivedMessageSqlTest extends TestCase
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

    public function testItAsksForARecipientRowForExactlyThisPair(): void
    {
        PeepSoMessageRepository::viewerReceivedMessage(7, 900);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('FROM wp_peepso_message_recipients', $sql);
        $this->assertStringContainsString('WHERE mrec_user_id = %d', $sql);
        $this->assertStringContainsString('AND mrec_msg_id = %d', $sql);
        $this->assertSame([7, 900], $this->wpdb->lastArgs());
    }

    /**
     * ⚠ The deliberate omission. If someone adds this filter, a message
     * the viewer can read in the thread becomes unrevealable.
     */
    public function testItDoesNotFilterOnMrecDeleted(): void
    {
        PeepSoMessageRepository::viewerReceivedMessage(7, 900);

        $this->assertStringNotContainsString(
            'mrec_deleted',
            $this->wpdb->lastSqlFlat(),
            'The thread read does not filter mrec_deleted, so neither may this; see the method docblock.'
        );
    }

    /** It scopes to ONE message, never to the conversation. */
    public function testItDoesNotScopeByConversation(): void
    {
        PeepSoMessageRepository::viewerReceivedMessage(7, 900);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringNotContainsString('mrec_parent_id', $sql);
    }

    /** Existence probe, not an aggregate. */
    public function testItIsAnExistenceProbeWithALimit(): void
    {
        PeepSoMessageRepository::viewerReceivedMessage(7, 900);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('SELECT 1', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
        $this->assertStringNotContainsString('COUNT(', $sql);
    }

    public function testARowPresentMeansReceived(): void
    {
        $this->wpdb->varToReturn = 1;

        $this->assertTrue(PeepSoMessageRepository::viewerReceivedMessage(7, 900));
    }

    /**
     * The case the whole method exists for: a participant with NO
     * recipient row — blocked pair, pre-join history, or a message they
     * deleted for themselves.
     */
    public function testNoRowMeansNotReceived(): void
    {
        $this->wpdb->varToReturn = null;

        $this->assertFalse(PeepSoMessageRepository::viewerReceivedMessage(7, 900));
    }

    public function testAnInvalidViewerOrMessageIssuesNoQuery(): void
    {
        $this->assertFalse(PeepSoMessageRepository::viewerReceivedMessage(0, 900));
        $this->assertFalse(PeepSoMessageRepository::viewerReceivedMessage(7, 0));
        $this->assertFalse(PeepSoMessageRepository::viewerReceivedMessage(-1, -1));
        $this->assertSame([], $this->wpdb->prepared, 'A nonsense pair must not cost a query.');
    }

    /**
     * Anti-vacuity: with the double returning null everything is false,
     * so a suite of only negative cases would pass against a method that
     * never queried at all.
     */
    public function testTheNegativeAnswerStillCameFromAQuery(): void
    {
        $this->wpdb->varToReturn = null;

        PeepSoMessageRepository::viewerReceivedMessage(7, 900);

        $this->assertCount(1, $this->wpdb->prepared);
    }
}
