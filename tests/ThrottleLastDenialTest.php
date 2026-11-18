<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Security\Throttle;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-289 — Throttle::lastDenial(), which the bcc-trust Envelope turns into a
 * 429's Retry-After / X-RateLimit-* / retry_after_seconds.
 *
 * Pinned:
 *   - only a DENY records it. An allowed call leaves it alone: null if
 *     nothing was denied, so a 429 of another kind (the captcha step-up,
 *     a cooldown) has nothing to inherit. An earlier denial survives later
 *     allowed calls;
 *   - on bcc-trust's RateLimiter, `retry_after` is the bucket's own time
 *     left, asked for with the SAME key, limit and window allowByKey() got;
 *   - a bcc-trust without retryAfterByKey(), or one answering a non-int,
 *     gives the window, as before; the wait is never below 1 s;
 *   - the object-cache backend and the fail-closed denies record the window.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ThrottleLastDenialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__thr'] = ['options' => [], 'cache' => [], 'ext_cache' => false, 'user_id' => 0, 'rl' => [], 'rl_allow' => true];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        require_once __DIR__ . '/Stubs/throttle-count-denied-stubs.php';
    }

    private static function withRateLimiter(bool $hasRetryAfter = true): void
    {
        $GLOBALS['__thr']['rl_has_retry_after'] = $hasRetryAfter;
        require_once __DIR__ . '/Stubs/throttle-ratelimiter-stub.php';
    }

    /** @return list<array<int, mixed>> */
    private static function calls(string $kind): array
    {
        return array_values(array_filter($GLOBALS['__thr']['rl'], static fn (array $c): bool => $c[0] === $kind));
    }

    /** @param array<string, int>|null $denial */
    private static function assertDenial(int $limit, int $window, int $retryAfter, ?array $denial, int $before): void
    {
        self::assertIsArray($denial);
        self::assertSame(['limit', 'window', 'at', 'retry_after'], array_keys($denial));
        self::assertSame($limit, $denial['limit']);
        self::assertSame($window, $denial['window']);
        self::assertSame($retryAfter, $denial['retry_after']);
        self::assertGreaterThanOrEqual($before, $denial['at']);
        self::assertLessThanOrEqual(time(), $denial['at']);
    }

    public function testAnAllowedCallRecordsNoDenial(): void
    {
        self::withRateLimiter();

        self::assertTrue(Throttle::allow('login', 20, 900, 'k'));
        self::assertTrue(Throttle::allow('login_account', 100, 900, 'k2'));

        self::assertNull(Throttle::lastDenial(), 'a 429 no throttle caused must find nothing to inherit');
        self::assertSame([], self::calls('retry_after'), 'the wait is only asked for on a deny');
    }

    public function testADenyRecordsTheBucketsTimeLeft(): void
    {
        self::withRateLimiter();
        $GLOBALS['__thr']['rl_allow']       = false;
        $GLOBALS['__thr']['rl_retry_after'] = 37;
        $before = time();

        self::assertFalse(Throttle::allow('vote', 5, 60, 'k'));

        self::assertDenial(5, 60, 37, Throttle::lastDenial(), $before);
    }

    /** The bucket asked is the bucket that refused: same key, limit and window. */
    public function testTheWaitIsAskedOfTheBucketThatRefused(): void
    {
        self::withRateLimiter();
        $GLOBALS['__thr']['rl_allow'] = false;

        // Explicit key, anonymous caller (network key), signed-in caller (user key).
        Throttle::allow('login_account', 100, 900, 'explicit', false);
        Throttle::allow('login', 20, 60);
        $GLOBALS['__thr']['user_id'] = 42;
        Throttle::allow('me_write', 10, 30);

        $asked = array_map(static fn (array $c): array => array_slice($c, 1, 3), self::calls('retry_after'));
        $tried = array_map(static fn (array $c): array => array_slice($c, 1, 3), self::calls('allow'));
        self::assertSame($tried, $asked);
        self::assertSame('explicit', $asked[0][0]);
        self::assertStringStartsWith('bcc_throttle_login_ip_', $asked[1][0]);
        self::assertSame(['bcc_throttle_me_write_42', 10, 30], $asked[2]);
    }

    public function testAnEarlierDenialSurvivesLaterAllowedCalls(): void
    {
        self::withRateLimiter();
        $GLOBALS['__thr']['rl_allow']       = false;
        $GLOBALS['__thr']['rl_retry_after'] = 37;
        $before = time();
        Throttle::allow('vote', 5, 60, 'k');

        $GLOBALS['__thr']['rl_allow'] = true;
        self::assertTrue(Throttle::allow('me_write', 120, 30, 'k2'));

        self::assertDenial(5, 60, 37, Throttle::lastDenial(), $before);
    }

    public function testALaterDenyReplacesIt(): void
    {
        self::withRateLimiter();
        $GLOBALS['__thr']['rl_allow']       = false;
        $GLOBALS['__thr']['rl_retry_after'] = 37;
        Throttle::allow('vote', 5, 60, 'k');

        $GLOBALS['__thr']['rl_retry_after'] = 400;
        $before = time();
        Throttle::allow('login_account', 100, 900, 'k2');

        self::assertDenial(100, 900, 400, Throttle::lastDenial(), $before);
    }

    public function testTheWaitIsNeverBelowOneSecond(): void
    {
        self::withRateLimiter();
        $GLOBALS['__thr']['rl_allow']       = false;
        $GLOBALS['__thr']['rl_retry_after'] = 0;
        $before = time();

        Throttle::allow('vote', 5, 60, 'k');

        self::assertDenial(5, 60, 1, Throttle::lastDenial(), $before);
    }

    /** A bcc-trust from before F-289 (no retryAfterByKey()): the window, as before. */
    public function testAnF290TrustGivesTheWindow(): void
    {
        self::withRateLimiter(false);
        $GLOBALS['__thr']['rl_allow'] = false;
        $before = time();

        self::assertFalse(Throttle::allow('vote', 5, 60, 'k'));

        self::assertDenial(5, 60, 60, Throttle::lastDenial(), $before);
    }

    public function testAnOlderTrustGivesTheWindow(): void
    {
        $GLOBALS['__thr']['rl_has_release'] = false;
        require_once __DIR__ . '/Stubs/throttle-ratelimiter-stub.php';
        $GLOBALS['__thr']['rl_allow'] = false;
        $before = time();

        self::assertFalse(Throttle::allow('vote', 5, 60, 'k'));

        self::assertDenial(5, 60, 60, Throttle::lastDenial(), $before);
    }

    public function testANonIntAnswerGivesTheWindow(): void
    {
        self::withRateLimiter();
        $GLOBALS['__thr']['rl_allow']       = false;
        $GLOBALS['__thr']['rl_retry_after'] = '37';
        $before = time();

        Throttle::allow('vote', 5, 60, 'k');

        self::assertDenial(5, 60, 60, Throttle::lastDenial(), $before);
    }

    /** Object cache (no bcc-trust): a fixed TTL bucket, so the window is the most a retry waits. */
    public function testAnObjectCacheDenyRecordsTheWindow(): void
    {
        $GLOBALS['__thr']['ext_cache'] = true;

        self::assertTrue(Throttle::allow('vote', 1, 60, 'oc'));
        self::assertNull(Throttle::lastDenial());

        $before = time();
        self::assertFalse(Throttle::allow('vote', 1, 60, 'oc'));
        self::assertDenial(1, 60, 60, Throttle::lastDenial(), $before);
    }

    /** No backend at all: fail closed, and still say how long. */
    public function testANoBackendDenyRecordsTheWindow(): void
    {
        require_once __DIR__ . '/Stubs/throttle-logger-stub.php';
        $before = time();

        self::assertFalse(Throttle::allow('vote', 5, 60, 'nb'));

        self::assertDenial(5, 60, 60, Throttle::lastDenial(), $before);
        self::assertCount(1, $GLOBALS['__thr']['logged'] ?? [], 'and the deny is still logged');
    }

    /** BCC_REQUIRE_TRUSTED_PROXY_CONFIG with proxy headers but no proxy list: fail closed, and still say how long. */
    public function testAProxyConfigDenyRecordsTheWindow(): void
    {
        self::withRateLimiter();
        require_once __DIR__ . '/Stubs/throttle-logger-stub.php';
        define('BCC_REQUIRE_TRUSTED_PROXY_CONFIG', true);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.4';
        $before = time();

        self::assertFalse(Throttle::allow('vote', 5, 60, 'px'));

        self::assertDenial(5, 60, 60, Throttle::lastDenial(), $before);
        self::assertSame([], self::calls('allow'), 'refused before any bucket is touched');
        self::assertCount(1, $GLOBALS['__thr']['logged'] ?? [], 'and the deny is still logged');
    }
}
