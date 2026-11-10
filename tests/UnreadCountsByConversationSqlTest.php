<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\BlockedSender;

use BCC\Core\Repositories\PeepSoMessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/blocked-sender-capture-wpdb.php';

/**
 * The per-conversation unread counts behind the inbox badge, with
 * bcc-trust #122's blocked-sender exclusion.
 *
 * ⚠ The dangerous mistake on this seam is not getting the predicate
 * wrong — it is getting the JOIN right. Reaching `post_author` tempts a
 * `JOIN wp_posts`, which would silently drop every recipient row whose
 * post row is missing and so change the count for viewers who block
 * nobody. A correlated `NOT EXISTS` leaves an orphaned row counted,
 * because an empty subquery makes the predicate true. That difference is
 * invisible in any test that only checks a blocked sender is excluded,
 * so it is asserted directly.
 */
#[CoversClass(PeepSoMessageRepository::class)]
final class UnreadCountsByConversationSqlTest extends TestCase
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

    public function testBlockedAuthorsAreExcludedInTheWhereClause(): void
    {
        PeepSoMessageRepository::getUnreadCountsByConversation(7, [100, 200]);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('JOIN wp_peepso_blocks b ON b.blk_blocked_id = p.post_author', $sql);
        $this->assertStringContainsString('WHERE p.ID = r.mrec_msg_id', $sql);
    }

    /**
     * ⚠ The orphan-safety assertion. `wp_posts` may appear ONLY inside
     * the correlated subquery.
     */
    public function testThePostsTableIsNotJoinedIntoTheOuterQuery(): void
    {
        PeepSoMessageRepository::getUnreadCountsByConversation(7, [100]);

        $sql   = $this->wpdb->lastSqlFlat();
        $at    = strpos($sql, 'NOT EXISTS');
        $this->assertIsInt($at, 'The exclusion must be a NOT EXISTS subquery.');
        $outer = substr($sql, 0, $at);

        $this->assertStringContainsString('FROM wp_peepso_message_recipients r', $outer);
        $this->assertStringNotContainsString(
            'JOIN',
            $outer,
            'The outer query must join nothing: an orphaned recipient row has to keep counting.'
        );
        $this->assertStringNotContainsString('wp_posts', $outer);
    }

    public function testTheExclusionIsOneDirectional(): void
    {
        PeepSoMessageRepository::getUnreadCountsByConversation(7, [100]);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('AND b.blk_user_id = %d', $sql);
        $this->assertStringNotContainsString('b.blk_blocked_id = %d', $sql);
    }

    public function testTheViewerIsBoundOnceForTheirRowsAndOnceForTheExclusion(): void
    {
        PeepSoMessageRepository::getUnreadCountsByConversation(7, [100, 200]);

        $this->assertSame([7, 100, 200, 7], $this->wpdb->lastArgs());
    }

    public function testTheCountIsStillGroupedPerConversation(): void
    {
        PeepSoMessageRepository::getUnreadCountsByConversation(7, [100]);

        $this->assertStringContainsString('GROUP BY mrec_parent_id', $this->wpdb->lastSqlFlat());
    }

    public function testThePreExistingUnreadAndDeletedPredicatesSurvive(): void
    {
        PeepSoMessageRepository::getUnreadCountsByConversation(7, [100]);

        $sql = $this->wpdb->lastSqlFlat();
        $this->assertStringContainsString('mrec_viewed = 0', $sql);
        $this->assertStringContainsString('mrec_deleted = 0', $sql);
    }

    public function testAnEmptyConversationSetIssuesNoQuery(): void
    {
        $this->assertSame([], PeepSoMessageRepository::getUnreadCountsByConversation(7, []));
        $this->assertSame([], $this->wpdb->prepared);
    }

    public function testTheReturnedMapIsKeyedByConversation(): void
    {
        $this->wpdb->resultsToReturn = [
            ['mrec_parent_id' => '100', 'c' => '3'],
            ['mrec_parent_id' => '200', 'c' => '1'],
        ];

        $out = PeepSoMessageRepository::getUnreadCountsByConversation(7, [100, 200]);

        $this->assertSame([100 => 3, 200 => 1], $out);
    }
}
