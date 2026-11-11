<?php

declare(strict_types=1);

namespace BCC\Core\Tests\Permissions;

use BCC\Core\Permissions\Permissions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * An UNKNOWN suspension state (the trust engine could not read it) is a real
 * third state, and it is never cached.
 *
 * Before, the trust engine had to answer true or false. A read failure in
 * bcc-trust came back as "no row", so every gate failed OPEN, and that
 * answer was cached for 60s. Now isSuspended() throws
 * SuspensionUnknownException:
 *   - is_not_suspended() denies THAT call (false), and does not cache it;
 *   - suspensionState() returns NULL, so a path with a persistent effect
 *     (session refresh, a queue worker, the rank sweep) can decline to act.
 * Known answers keep their 60s cache and their admin-bypass behaviour.
 */
#[CoversClass(Permissions::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SuspensionStateTest extends TestCase
{
    private const USER  = 140;
    private const ADMIN = 1;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/suspension-state-stubs.php';
        $GLOBALS['__susp'] = ['answers' => [], 'calls' => 0, 'admins' => [self::ADMIN], 'cache' => []];
    }

    public function testAnUnknownStateDeniesThatCallAndIsNotCached(): void
    {
        $GLOBALS['__susp']['answers'] = ['unknown', false];

        self::assertFalse(Permissions::is_not_suspended(self::USER, false), 'unknown → deny this call');
        self::assertSame([], $GLOBALS['__susp']['cache'], 'an unknown answer is never cached');

        self::assertTrue(Permissions::is_not_suspended(self::USER, false), 'the next call re-reads and sees the real answer');
        self::assertSame(2, $GLOBALS['__susp']['calls']);
    }

    public function testSuspensionStateReturnsNullForUnknown(): void
    {
        $GLOBALS['__susp']['answers'] = ['unknown'];

        self::assertNull(Permissions::suspensionState(self::USER, false));
        self::assertSame([], $GLOBALS['__susp']['cache']);
    }

    public function testKnownAnswersAreReturnedAndCached(): void
    {
        $GLOBALS['__susp']['answers'] = [true];

        self::assertTrue(Permissions::suspensionState(self::USER, false));
        self::assertFalse(Permissions::is_not_suspended(self::USER, false), 'served from the cache');
        self::assertSame(1, $GLOBALS['__susp']['calls'], 'a known answer is cached as before');
    }

    public function testNotSuspendedIsFalseNotNull(): void
    {
        $GLOBALS['__susp']['answers'] = [false];

        self::assertFalse(Permissions::suspensionState(self::USER, false));
        self::assertTrue(Permissions::is_not_suspended(self::USER, false));
    }

    public function testAdminBypassStillAnswersNotSuspendedWithoutReading(): void
    {
        $GLOBALS['__susp']['answers'] = ['unknown'];

        self::assertFalse(Permissions::suspensionState(self::ADMIN, true));
        self::assertTrue(Permissions::is_not_suspended(self::ADMIN, true));
        self::assertSame(0, $GLOBALS['__susp']['calls']);
    }

    public function testAnAnonymousCallerIsDenied(): void
    {
        self::assertTrue(Permissions::suspensionState(0));
        self::assertFalse(Permissions::is_not_suspended(0));
    }
}
