<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoAddPostGuard;
use BCC\Core\PeepSo\PeepSoCommentWriter;
use BCC\Core\PeepSo\PeepSoMessageWriter;
use BCC\Core\PeepSo\PeepSoStatusWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-361, second half — the write paths that reach the same listeners by
 * routes that are NOT `add_post()`.
 *
 * Three of them, and each is a different mechanism rather than three copies
 * of one:
 *
 *   1. `add_comment()` (activity.php:364) fires a DIFFERENT hook,
 *      `peepso_after_add_comment`, whose listeners take four arguments.
 *      `PeepSoPagesPlugin::after_add_comment` (pages.php:3097) reads
 *      `post_as_page` from the request, so any member commenting on a page's
 *      post could have their comment render as authored BY the page.
 *
 *   2. The messages model fires `peepso_activity_after_add_post` **directly**
 *      (messagesmodel.php:321 and :500). Neither `create_new_conversation()`
 *      nor `add_to_conversation()` calls `add_post()`, so the whole
 *      request-driven listener family runs on a message send without the
 *      function that normally fires that hook.
 *
 *   3. `createSelfBlogPost()` is a bare `wp_insert_post()`. Three PeepSo
 *      listeners sit on that CORE hook and read the request: moods
 *      (`mood`), location (`location`) and the anonymous-OP marker
 *      (`anon_id`). ⚠ `wp_insert_post` can never be detached, so the PIN is
 *      the only control — which is what {@see PeepSoAddPostGuard::pinOnly()}
 *      is for.
 *
 * Same boundary as the first half: the listener bodies are faithful
 * re-implementations cited line by line in the shared stubs, and the guard
 * under test is the real one.
 */
#[CoversClass(PeepSoAddPostGuard::class)]
#[CoversClass(PeepSoCommentWriter::class)]
#[CoversClass(PeepSoMessageWriter::class)]
#[CoversClass(PeepSoStatusWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoGuardCommentsMessagesTest extends TestCase
{
    private const AUTHOR     = 51;
    private const RECIPIENT  = 52;
    private const PARENT     = 4242;
    private const COMMENT_ID = 5151;
    private const MESSAGE_ID = 6161;
    private const BLOG_ID    = 7171;
    private const PAGE_ID    = 77;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/add-post-guard-stubs.php';

        $GLOBALS['__bcc_apg'] = [
            'meta' => [], 'ran' => [], 'fetched' => [], 'anon' => [],
            'albums' => [], 'deleted' => [], 'add_post' => [], 'add_comment' => [],
            'messages' => [], 'notified' => [], 'raw' => [], 'logged' => [],
            'actions' => [],
            // The pages comment listener is inert unless the ROOT post is a
            // page post, so the fixture says it is — otherwise the test below
            // would pass for want of a precondition.
            'root_page_id' => self::PAGE_ID,
        ];
        $GLOBALS['wp_filter'] = [];
        $_GET     = [];
        $_POST    = [];
        $_REQUEST = [];

        // The comment hook, at PeepSo's real priorities and arities.
        \__bcc_apg_add('peepso_after_add_comment', [new \PeepSoActivityStream(), 'activity_after_add_comment'], 10, 4);
        \__bcc_apg_add('peepso_after_add_comment', [new \PeepSoTags(), 'after_save_comment'], 10, 4);
        \__bcc_apg_add('peepso_after_add_comment', [new \PeepSo3_Anon(), 'save_comments_anon_op'], 100, 4);
        \__bcc_apg_add('peepso_after_add_comment', '__bcc_apg_pages_after_add_comment', 10, 4);
        \__bcc_apg_add('peepso_after_add_comment', [new \PeepSoGiphy(), 'after_add_post'], 10, 4);
        \__bcc_apg_add('peepso_after_add_comment', [new \PeepSoSharePhotos(), 'after_add_post'], 10, 4);

        // The post hook, which the MESSAGES model fires directly.
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoActivity(), 'handle_embed_data'], 10, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoTags(), 'after_save_post'], 10, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoSharePhotos(), 'after_add_post'], 20, 2);
        \__bcc_apg_add('peepso_activity_after_add_post', [new \PeepSoGroupsPlugin(), 'after_add_post'], 10, 2);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_apg'], $GLOBALS['wp_filter']);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function meta(int $postId): array
    {
        $meta = $GLOBALS['__bcc_apg']['meta'][$postId] ?? [];
        self::assertIsArray($meta);

        return $meta;
    }

    /** @return list<string> */
    private function ran(): array
    {
        $ran = $GLOBALS['__bcc_apg']['ran'] ?? [];
        self::assertIsArray($ran);

        return array_values($ran);
    }

    // ── 1. comments ─────────────────────────────────────────────────────

    /**
     * ⭐ `?post_as_page=1` must not make a member's comment look like the
     * page's own words.
     */
    public function testAQueryStringCannotForgePageAuthorshipOnAComment(): void
    {
        $_GET['post_as_page'] = '1';

        $commentId = PeepSoCommentWriter::addComment(self::PARENT, self::AUTHOR, 'hello', 1);

        self::assertSame(self::COMMENT_ID, $commentId, 'the legitimate comment must still be written');
        self::assertArrayNotHasKey('peepso_post_as_page', $this->meta(self::COMMENT_ID));
        self::assertNotContains('PeepSoPagesPlugin::after_add_comment', $this->ran());
    }

    /**
     * ⚠ ANTI-VACUITY: the pages listener DOES forge authorship when it is not
     * detached, and the fixture's precondition (root post is a page post) is
     * really in place. Without this, the test above could pass because
     * nothing reads `post_as_page` at all.
     */
    public function testTheFixturePagesListenerDoesForgeAuthorship(): void
    {
        $_GET['post_as_page'] = '1';

        \__bcc_apg_pages_after_add_comment(self::COMMENT_ID, 888, false, false);

        self::assertSame(1, $this->meta(self::COMMENT_ID)['peepso_post_as_page'] ?? null);
    }

    /**
     * The three content-driven comment listeners keep running: the root-post
     * bump (so a reply still resurfaces a thread), @mentions, and the
     * anonymous-OP marker (dropping it would de-anonymise an anonymous
     * poster's own replies).
     */
    public function testTheContentDrivenCommentListenersStillRun(): void
    {
        PeepSoCommentWriter::addComment(self::PARENT, self::AUTHOR, 'hello @someone', 1);

        $ran = $this->ran();
        self::assertContains('PeepSoActivityStream::activity_after_add_comment', $ran);
        self::assertContains('PeepSoTags::after_save_comment', $ran);
        self::assertContains('PeepSo3_Anon::save_comments_anon_op', $ran);
    }

    /**
     * The BACKSTOP for a comment STRIPS rather than deletes.
     *
     * ⚠ The destructive path would take nested replies and likes with it —
     * the reason `PeepSoCommentWriter::deleteComment()` trashes a wp_post
     * instead of calling PeepSo's `delete_post()`. The comment is the
     * member's own words; only the forged stamp is not.
     */
    public function testAForgedCommentStampIsStrippedAndTheCommentKept(): void
    {
        $GLOBALS['__bcc_apg']['meta'][self::COMMENT_ID]['peepso_post_as_page'] = 1;

        $reason = PeepSoAddPostGuard::stripForgedStamps(self::COMMENT_ID);

        self::assertSame('forged_stamp_stripped', $reason);
        self::assertArrayNotHasKey('peepso_post_as_page', $this->meta(self::COMMENT_ID));
        self::assertSame([], $GLOBALS['__bcc_apg']['deleted'], 'the comment must NOT be deleted');
        self::assertNotSame([], $GLOBALS['__bcc_apg']['logged']);
    }

    public function testACleanCommentIsLeftAlone(): void
    {
        self::assertNull(PeepSoAddPostGuard::stripForgedStamps(self::COMMENT_ID));
    }

    // ── 2. messages ─────────────────────────────────────────────────────

    /**
     * ⭐ A message send reaches the post hook DIRECTLY, with no `add_post()`
     * in the call stack, so the embed fetcher's blind SSRF was live on
     * BCC's message routes.
     */
    public function testAMessageSendDoesNotFetchAStrayEmbed(): void
    {
        $_POST['type']  = 'activity';
        $_POST['embed'] = 'http://169.254.169.254/latest/meta-data/';

        $result = PeepSoMessageWriter::sendNewMessage(self::AUTHOR, self::RECIPIENT, 'hi');

        self::assertIsArray($result);
        self::assertSame(self::MESSAGE_ID, $result['message_id'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_apg']['fetched']);
    }

    public function testAMessageSendCannotPlantAnUploadViaAnonId(): void
    {
        $_GET['anon_id'] = '777';
        $_GET['type']    = 'photo';
        $_GET['files']   = ['x.jpg'];

        PeepSoMessageWriter::sendNewMessage(self::AUTHOR, self::RECIPIENT, 'hi');

        self::assertSame([], $GLOBALS['__bcc_apg']['anon']);
    }

    public function testAReplyInAConversationIsGuardedToo(): void
    {
        $_POST['type']  = 'activity';
        $_POST['embed'] = 'http://169.254.169.254/latest/meta-data/';

        $messageId = PeepSoMessageWriter::sendInConversation(self::AUTHOR, 4242, 'hi');

        self::assertSame(6262, $messageId);
        self::assertSame([], $GLOBALS['__bcc_apg']['fetched']);
    }

    /** ⚠ ANTI-VACUITY: a message send still works and still runs @mentions. */
    public function testAMessageSendStillRunsMentions(): void
    {
        PeepSoMessageWriter::sendNewMessage(self::AUTHOR, self::RECIPIENT, 'hi @someone');

        self::assertContains('PeepSoTags::after_save_post', $this->ran());
    }

    // ── 3. pin-only, on a bare wp_insert_post ───────────────────────────

    /**
     * ⭐ `mood` and `location` were MISSED by the first pass, and they are
     * read on `wp_insert_post` — a CORE hook, so there is nothing to detach.
     * The pin is the only control that reaches them.
     */
    public function testStrayMoodAndLocationDoNotStickToABlogPost(): void
    {
        $_GET['mood']     = '7';
        $_GET['location'] = 'Planted Place';
        $_GET['anon_id']  = '777';

        $postId = PeepSoStatusWriter::createSelfBlogPost(self::AUTHOR, 'excerpt', 'full text');

        self::assertSame(self::BLOG_ID, $postId, 'the blog post must still be written');
        self::assertArrayNotHasKey('peepso_post_mood', $this->meta(self::BLOG_ID));
        self::assertArrayNotHasKey('peepso_post_location', $this->meta(self::BLOG_ID));
        self::assertArrayNotHasKey('peepso_post_anon_op', $this->meta(self::BLOG_ID));
    }

    /**
     * ⚠ ANTI-VACUITY, and it also pins that pin-only really does NOT detach:
     * all three core-hook listeners must still have RUN. If the guard had
     * emptied `wp_insert_post`, this test would fail — and emptying a core
     * WordPress hook is the mistake this mode exists to avoid.
     */
    public function testPinOnlyLeavesTheCoreHookListenersRunning(): void
    {
        $_GET['mood'] = '7';

        PeepSoStatusWriter::createSelfBlogPost(self::AUTHOR, 'excerpt', 'full text');

        $ran = $this->ran();
        self::assertContains('PeepSoMoods::save_mood', $ran);
        self::assertContains('PeepSoLocation::insert_post', $ran);
        self::assertContains('PeepSo3_Anon::save_anon_op', $ran);
    }

    /** ⚠ ANTI-VACUITY for the fixture: unpinned, those keys DO stick. */
    public function testTheFixtureCoreHookReadersDoWriteWhenUnpinned(): void
    {
        $_GET['mood']     = '7';
        $_GET['location'] = 'Planted Place';

        (new \PeepSoMoods())->save_mood(self::BLOG_ID);
        (new \PeepSoLocation())->insert_post(self::BLOG_ID);

        self::assertSame(7, $this->meta(self::BLOG_ID)['peepso_post_mood'] ?? null);
        self::assertSame('Planted Place', $this->meta(self::BLOG_ID)['peepso_post_location'] ?? null);
    }

    // ── restoration across several hooks ────────────────────────────────

    /** @return array<int, list<string>> priority => SORTED callback ids */
    private function shapeOf(string $hook): array
    {
        $registry = $GLOBALS['wp_filter'][$hook] ?? null;
        self::assertInstanceOf(\FakeWpHook::class, $registry);

        $out = [];
        foreach ($registry->callbacks as $priority => $callbacks) {
            $ids = array_keys($callbacks);
            sort($ids);
            $out[(int) $priority] = $ids;
        }
        ksort($out);

        return $out;
    }

    public function testBothHooksAreRestoredAfterAMultiHookGuard(): void
    {
        $before = [
            'peepso_activity_after_add_post' => $this->shapeOf('peepso_activity_after_add_post'),
            'peepso_after_add_comment'       => $this->shapeOf('peepso_after_add_comment'),
        ];

        PeepSoAddPostGuard::aroundHooks(
            ['peepso_activity_after_add_post', 'peepso_after_add_comment'],
            [PeepSoAddPostGuard::KEEP_MENTIONS],
            [],
            static fn (): int => 1
        );

        foreach ($before as $hook => $shape) {
            self::assertSame($shape, $this->shapeOf($hook), $hook);
        }
    }

    /**
     * ⚠⚠ A LIMITATION, asserted rather than left to be discovered: every
     * callback comes back, but ORDER WITHIN A PRIORITY is not preserved.
     *
     * A detached callback is re-added with `add_action()`, which appends it to
     * the end of its priority bucket, while a KEPT callback never moved. So a
     * detached listener that used to run before a kept one at the same
     * priority now runs after it.
     *
     * Accepted rather than fixed. Restoring exact intra-priority order would
     * mean detaching and rebuilding the kept callbacks too — which is the
     * snapshot-and-replace this guard deliberately avoids, because PeepSo
     * registers some of these callbacks temporarily
     * (peepso-groups/groups.php:2195, peepso-pages/pages.php:3849) and a
     * rebuild would clobber whatever the vendor did during our write. None of
     * the listeners here depends on another's position, and a listener that
     * genuinely cares about sequence uses a priority — which IS preserved.
     *
     * This test exists so the next person reads the trade-off instead of
     * finding it.
     */
    public function testOrderWithinAPriorityIsNotPreservedButEveryCallbackReturns(): void
    {
        $hook   = 'peepso_activity_after_add_post';
        $before = array_keys($GLOBALS['wp_filter'][$hook]->callbacks[10]);

        PeepSoAddPostGuard::aroundHooks([$hook], [PeepSoAddPostGuard::KEEP_MENTIONS], [], static fn (): int => 1);

        $after = array_keys($GLOBALS['wp_filter'][$hook]->callbacks[10]);

        // Same set, and the kept one is still there…
        self::assertSame(count($before), count($after));
        self::assertSame([], array_diff($before, $after), 'a callback did not come back');
        self::assertContains('PeepSoTags::after_save_post', $after);
        // …but the detached ones moved to the end of the bucket.
        self::assertNotSame($before, $after, 'if order IS preserved now, delete this test and tighten the one above');
        self::assertSame('PeepSoTags::after_save_post', $after[0]);
    }
}
