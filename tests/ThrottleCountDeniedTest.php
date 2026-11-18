<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Security\Throttle;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-290 — Throttle::allow(..., $countDenied) and Throttle::release().
 *
 * The auth routes opt into countDenied=false so a member retrying while
 * blocked doesn't extend the block, and release() a successful sign-in's
 * slot. Every other caller keeps the default: pessimistic accounting.
 * Pinned:
 *   - the default passes countDenied=true to bcc-trust's RateLimiter;
 *     false is passed through when asked for;
 *   - an older bcc-trust (three-parameter allowByKey) simply ignores it;
 *   - release() reaches releaseByKey() with the SAME key allow() used —
 *     explicit keys, signed-in callers and anonymous callers alike — and is
 *     a no-op on a bcc-trust without releaseByKey();
 *   - on the object-cache backend: a default deny keeps its slot; a
 *     countDenied=false deny gives it back; release() decrements but never
 *     below zero.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ThrottleCountDeniedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__thr'] = ['options' => [], 'cache' => [], 'ext_cache' => false, 'user_id' => 0, 'rl' => [], 'rl_allow' => true];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        require_once __DIR__ . '/Stubs/throttle-count-denied-stubs.php';
    }

    private static function withRateLimiter(bool $hasRelease = true): void
    {
        $GLOBALS['__thr']['rl_has_release'] = $hasRelease;
        require_once __DIR__ . '/Stubs/throttle-ratelimiter-stub.php';
    }

    /** @return list<array<int, mixed>> */
    private static function calls(string $kind): array
    {
        return array_values(array_filter($GLOBALS['__thr']['rl'], static fn (array $c): bool => $c[0] === $kind));
    }

    public function testTheDefaultStillCountsDeniedCalls(): void
    {
        self::withRateLimiter();

        Throttle::allow('vote', 5, 60, 'k1');

        self::assertSame([['allow', 'k1', 5, 60, true]], self::calls('allow'), 'every existing caller stays pessimistic');
    }

    public function testCountDeniedFalseIsPassedThrough(): void
    {
        self::withRateLimiter();

        Throttle::allow('login', 20, 60, 'k2', false);

        self::assertSame([['allow', 'k2', 20, 60, false]], self::calls('allow'));
    }

    public function testAnOlderTrustIgnoresTheFlag(): void
    {
        self::withRateLimiter(false);

        self::assertTrue(Throttle::allow('login', 20, 60, 'k3', false));
        Throttle::release('login', 60, 'k3');

        self::assertCount(1, self::calls('allow'));
        self::assertSame([], self::calls('release'), 'no releaseByKey(): release() is a no-op');
    }

    public function testReleaseUsesTheSameKeyAsAllow(): void
    {
        self::withRateLimiter();

        // Explicit key.
        Throttle::allow('login_account', 100, 900, 'explicit', false);
        Throttle::release('login_account', 900, 'explicit');
        // Anonymous caller: the network key both derive.
        Throttle::allow('login', 20, 60, null, false);
        Throttle::release('login', 60);
        // Signed-in caller: the per-user key both derive.
        $GLOBALS['__thr']['user_id'] = 42;
        Throttle::allow('me_write', 10, 60);
        Throttle::release('me_write', 60);

        $allowKeys   = array_column(self::calls('allow'), 1);
        $releaseKeys = array_column(self::calls('release'), 1);
        self::assertSame($allowKeys, $releaseKeys);
        self::assertSame('explicit', $releaseKeys[0]);
        self::assertStringStartsWith('bcc_throttle_login_ip_', $releaseKeys[1]);
        self::assertSame('bcc_throttle_me_write_42', $releaseKeys[2]);
        self::assertSame([900, 60, 60], array_column(self::calls('release'), 2), 'the window travels too');
    }

    /** Object-cache backend (no bcc-trust): count under the same key allow() uses. */
    private static function cacheCount(string $key): int
    {
        return (int) ($GLOBALS['__thr']['cache']['bcc_throttle:bcc_throttle_' . md5($key)] ?? 0);
    }

    public function testOnTheObjectCacheADefaultDenyKeepsItsSlot(): void
    {
        $GLOBALS['__thr']['ext_cache'] = true;

        self::assertTrue(Throttle::allow('vote', 1, 60, 'oc1'));
        self::assertFalse(Throttle::allow('vote', 1, 60, 'oc1'));
        self::assertFalse(Throttle::allow('vote', 1, 60, 'oc1'));

        self::assertSame(3, self::cacheCount('oc1'), 'pessimistic: every call counted');
    }

    public function testOnTheObjectCacheAnOptInDenyGivesItsSlotBack(): void
    {
        $GLOBALS['__thr']['ext_cache'] = true;

        self::assertTrue(Throttle::allow('login', 1, 60, 'oc2', false));
        self::assertFalse(Throttle::allow('login', 1, 60, 'oc2', false));
        self::assertFalse(Throttle::allow('login', 1, 60, 'oc2', false));

        self::assertSame(1, self::cacheCount('oc2'), 'retrying while blocked does not extend the block');
    }

    public function testOnTheObjectCacheReleaseDecrementsButNeverBelowZero(): void
    {
        $GLOBALS['__thr']['ext_cache'] = true;

        Throttle::allow('login', 5, 60, 'oc3', false);
        Throttle::allow('login', 5, 60, 'oc3', false);
        Throttle::release('login', 60, 'oc3');
        self::assertSame(1, self::cacheCount('oc3'));

        Throttle::release('login', 60, 'oc3');
        Throttle::release('login', 60, 'oc3');
        self::assertSame(0, self::cacheCount('oc3'));
    }
}
