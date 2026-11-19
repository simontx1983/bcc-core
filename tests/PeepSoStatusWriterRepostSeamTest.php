<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoStatusWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-346 — the one seam into `act_repost_id`.
 *
 * ⚠ WHAT IS ASSERTED, and why it has to be the ARGUMENT. PeepSo owns
 * everything after `$extra['repost']` is handed over: it resolves the chain
 * root, writes the column and marks the root dirty for its own ranking
 * recompute. No return value shows any of that, so `add_post()` is a spy and
 * these read the handed-over array. A test on the result would pass with the
 * parameter never sent.
 *
 * ⚠⚠ THE PARAMETER IS NAMED FOR THE ROOT ON PURPOSE. PeepSo calls
 * `get_repost_root()` before storing (activity.php:153-155), so what lands in
 * `act_repost_id` is always the chain ROOT, never the id handed in. The
 * once-per-post rule and its 409 therefore have to be keyed on the root as
 * well — keyed on a submitted repost's own act_id, the rule is bypassed by
 * reposting someone else's repost of the same post.
 */
#[CoversClass(PeepSoStatusWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoStatusWriterRepostSeamTest extends TestCase
{
    private const AUTHOR = 51;
    private const ROOT   = 9091;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/status-writer-audience-stubs.php';
        $GLOBALS['__bcc_swa'] = ['meta' => [], 'add_post' => []];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_swa']);
        parent::tearDown();
    }

    /** @return array<string, mixed> The one recorded add_post() call. */
    private function onlyCall(): array
    {
        $calls = $GLOBALS['__bcc_swa']['add_post'] ?? [];
        self::assertIsArray($calls);
        self::assertCount(1, $calls, 'expected exactly one add_post()');

        return $calls[0];
    }

    /** @return array<string, mixed> */
    private function extra(): array
    {
        $extra = $this->onlyCall()['extra'] ?? [];
        self::assertIsArray($extra);

        return $extra;
    }

    /** ⭐ THE SEAM. */
    public function testTheRepostRootIsHandedToPeepSoAsTheRepostExtra(): void
    {
        $result = PeepSoStatusWriter::createSelfStatus(
            self::AUTHOR,
            'worth a look',
            0,
            'members_only',
            self::ROOT
        );

        self::assertSame(true, $result['ok'] ?? null);
        self::assertSame(self::ROOT, $this->extra()['repost'] ?? null);
    }

    /**
     * ⚠ ANTI-VACUITY: an ordinary status must not carry the key at all.
     * `add_post` treats `!empty($extra['repost'])` as "this is a repost", so a
     * stray `repost => 0` would be harmless but a stray non-zero would turn
     * every status into a repost of something.
     */
    public function testAnOrdinaryStatusCarriesNoRepostKey(): void
    {
        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'just a post');

        self::assertArrayNotHasKey('repost', $this->extra());
    }

    /**
     * A bare repost — no comment of your own — is a legitimate post, and
     * PeepSo agrees: its `add_post` refuses empty content only when there is
     * no repost either (activity.php:163-165). The writer's guard is relaxed
     * in exactly that shape.
     */
    public function testABareRepostWithNoCommentIsAccepted(): void
    {
        $result = PeepSoStatusWriter::createSelfStatus(self::AUTHOR, '   ', 0, 'members_only', self::ROOT);

        self::assertSame(true, $result['ok'] ?? null);
        self::assertSame(self::ROOT, $this->extra()['repost'] ?? null);
    }

    /**
     * ⚠ ANTI-VACUITY for the relaxation: the empty-content guard is RELAXED,
     * not removed. Without this, deleting the guard outright would pass
     * everything above.
     */
    public function testAnEmptyNonRepostIsStillRefused(): void
    {
        $result = PeepSoStatusWriter::createSelfStatus(self::AUTHOR, '   ');

        self::assertSame(false, $result['ok'] ?? null);
        self::assertSame('empty_content', $result['reason'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_swa']['add_post'], 'nothing should have been written');
    }

    /**
     * ⚠ The repost key must sit BESIDE F-291's `act_access`, not instead of
     * it. Both are `$extra` entries written by the same block, so an edit
     * that reassigned `$extra` rather than adding to it would silently drop
     * the member's chosen audience on every repost.
     */
    public function testTheRepostKeySitsBesideTheChosenAudience(): void
    {
        $GLOBALS['__bcc_swa']['meta'][self::AUTHOR] = '40';

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'worth a look', 0, 'members_only', self::ROOT);

        $extra = $this->extra();
        self::assertSame(self::ROOT, $extra['repost'] ?? null);
        // ⚠ An INT: storedDefaultAudience() casts the stored meta before
        // handing it over, so `act_access` is 40 and not '40'.
        self::assertSame(40, $extra['act_access'] ?? null, 'the chosen audience was dropped');
    }
}
