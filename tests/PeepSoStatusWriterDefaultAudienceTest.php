<?php

declare(strict_types=1);

namespace BCC\Core\PeepSo\Tests;

use BCC\Core\PeepSo\PeepSoStatusWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-291 — a member's DEFAULT POST AUDIENCE reaches their own-wall posts.
 *
 * Before this, `createSelfStatus()` called PeepSo's `add_post()` with no
 * `$extra`, so PeepSo fell back to the author's PROFILE accessibility
 * (activity.php:232) and then OVERWROTE `peepso_last_used_post_privacy`
 * with that value (:260). The stored setting was not merely unread — it was
 * clobbered by the next post, so a member who chose "Only me" watched the
 * setting revert on its own.
 *
 * ⚠ WHAT IS ASSERTED, and why it has to be the ARGUMENT. PeepSo owns the
 * decision once it has `$extra`; what this layer must get right is whether
 * it passes `act_access` at all and what value. No return value shows that,
 * so `add_post()` is a spy and these assertions read the handed-over array.
 * A test on the result would pass with the parameter never sent.
 */
#[CoversClass(PeepSoStatusWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoStatusWriterDefaultAudienceTest extends TestCase
{
    private const AUTHOR = 51;

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

    /**
     * ⭐ THE FIX. A stored audience is handed to PeepSo as `act_access`, so
     * the post lands at the level the member chose — and PeepSo's write-back
     * of `peepso_last_used_post_privacy` becomes a no-op, because what it
     * stores is what we just passed. That is why this needs no second field.
     */
    public function testAStoredAudienceIsPassedAsActAccess(): void
    {
        $GLOBALS['__bcc_swa']['meta'][self::AUTHOR] = '40';

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertSame(40, $this->onlyCall()['extra']['act_access']);
    }

    /** Every rung of PeepSo's ladder round-trips, not just the strict end. */
    public function testEveryLadderValueIsPassedThrough(): void
    {
        foreach ([10, 20, 30, 40] as $level) {
            $GLOBALS['__bcc_swa'] = ['meta' => [self::AUTHOR => (string) $level], 'add_post' => []];

            PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

            self::assertSame(
                $level,
                $this->onlyCall()['extra']['act_access'],
                'level ' . $level . ' must reach PeepSo unchanged'
            );
        }
    }

    /**
     * ⭐⭐ CONTROL — an UNSET preference must pass NOTHING. Inventing a
     * default here would change the audience of the next post on every
     * existing account, which is a silent mass behaviour change and the
     * opposite of the fix. PeepSo's own profile-level fallback must still
     * run, and it only runs when `act_access` is absent.
     */
    public function testAnUnsetPreferencePassesNoActAccess(): void
    {
        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        self::assertArrayNotHasKey('act_access', $this->onlyCall()['extra']);
    }

    /**
     * ⚠⚠ AN UNRECOGNISED VALUE ALSO PASSES NOTHING, and this is the one
     * that would hurt. §F-02's read gate DENIES an unknown level, so
     * writing a stray value would produce a post visible to NOBODY —
     * including its author — with no setting that explains why. Falling
     * back to PeepSo's behaviour is strictly safer than honouring garbage.
     */
    public function testAnUnrecognisedValuePassesNoActAccess(): void
    {
        // ⚠ The ARRAY case is not hypothetical: get_user_meta can answer
        // with one for malformed or multi-row meta, and it is the only
        // value that reaches the `!is_scalar()` guard. Without it that
        // guard is untested and a mutation returning a default from there
        // survives.
        foreach (['', '0', '15', '41', '-10', 'public', 'abc', '10.5', [40], [], null] as $stored) {
            $GLOBALS['__bcc_swa'] = ['meta' => [self::AUTHOR => $stored], 'add_post' => []];

            PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

            self::assertArrayNotHasKey(
                'act_access',
                $this->onlyCall()['extra'],
                var_export($stored, true) . ' must not be written as an access level'
            );
        }
    }

    /**
     * ⭐⭐ GROUP POSTS ARE EXCLUDED (user decision, 2026-11-12). Inside a
     * group the ROOM is the audience — the ratified §F-02 carve-out — and
     * because BCC's writer leaves `act_access` to the author's profile
     * level, applying a personal "only me" default here would empty a Hall
     * of every post by that member. Asserted WITH a stored preference set,
     * which is the only state in which the exclusion can fail.
     */
    public function testAGroupPostIgnoresTheDefaultAudience(): void
    {
        $GLOBALS['__bcc_swa']['meta'][self::AUTHOR] = '40';

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello', 1138, 'members_only');

        self::assertArrayNotHasKey(
            'act_access',
            $this->onlyCall()['extra'],
            'a group create must keep PeepSo behaviour exactly'
        );
    }

    /**
     * The preference is read for the AUTHOR, not for whoever happens to be
     * the current user. These writers run on cron and admin paths too, and
     * a `get_current_user_id()` here would silently apply one member's
     * audience to another member's post.
     */
    public function testThePreferenceIsReadForTheAuthor(): void
    {
        $GLOBALS['__bcc_swa']['meta'][self::AUTHOR] = '20';
        $GLOBALS['__bcc_swa']['meta'][999]          = '40';

        PeepSoStatusWriter::createSelfStatus(self::AUTHOR, 'hello');

        $call = $this->onlyCall();
        self::assertSame(self::AUTHOR, $call['author']);
        self::assertSame(20, $call['extra']['act_access'], 'the AUTHOR’s level, not another user’s');
    }
}
