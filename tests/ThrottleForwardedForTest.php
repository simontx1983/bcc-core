<?php

declare(strict_types=1);

namespace BCC\Core\Security\Tests;

use BCC\Core\Security\Throttle;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-54 — behind a trusted proxy, the client IP is the RIGHTMOST untrusted
 * X-Forwarded-For hop, not the leftmost.
 *
 * A proxy APPENDS the peer it saw, so the leftmost XFF entries are whatever
 * the client sent. Taking entry [0] let any client behind a trusted proxy
 * choose the IP its rate-limit bucket is keyed on. Pinned against the REAL
 * Throttle::getClientIp() (Mode 1: BCC_TRUSTED_PROXY_IPS; one case Mode 2,
 * the bcc_trusted_proxy_ips filter):
 *   - a forged leftmost hop is ignored; the hop the proxy appended wins;
 *   - a chain of trusted proxies is skipped;
 *   - invalid entries are skipped;
 *   - nothing untrusted in XFF → X-Real-IP; neither → REMOTE_ADDR;
 *   - REMOTE_ADDR not trusted → every header ignored (unchanged).
 *
 * Subprocess-only: BCC_TRUSTED_PROXY_IPS is a constant, defined per test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ThrottleForwardedForTest extends TestCase
{
    private const EDGE  = '10.0.0.1';
    private const INNER = '10.0.0.2';

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CF_CONNECTING_IP']);
        if ($this->name() !== 'testTheFilterAllowlistUsesTheSameRightWalk') {
            define('BCC_TRUSTED_PROXY_IPS', self::EDGE . ', ' . self::INNER);
        }
    }

    private static function clientIp(string $remoteAddr, ?string $xff = null, ?string $realIp = null): string
    {
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        if ($xff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
        }
        if ($realIp !== null) {
            $_SERVER['HTTP_X_REAL_IP'] = $realIp;
        }
        $m = new \ReflectionMethod(Throttle::class, 'getClientIp');
        return (string) $m->invoke(null);
    }

    public function testAForgedLeftmostHopIsIgnored(): void
    {
        // The client sent "X-Forwarded-For: 6.6.6.6"; our edge appended the
        // peer it actually saw.
        self::assertSame('203.0.113.9', self::clientIp(self::EDGE, '6.6.6.6, 203.0.113.9'));
    }

    public function testAChainOfTrustedProxiesIsSkipped(): void
    {
        self::assertSame('203.0.113.9', self::clientIp(self::EDGE, '6.6.6.6, 203.0.113.9, ' . self::INNER));
    }

    public function testInvalidEntriesAreSkipped(): void
    {
        self::assertSame('203.0.113.9', self::clientIp(self::EDGE, '203.0.113.9, not-an-ip, , ' . self::INNER));
    }

    public function testAllTrustedFallsBackToXRealIp(): void
    {
        self::assertSame('198.51.100.7', self::clientIp(self::EDGE, self::INNER, '198.51.100.7'));
    }

    public function testNoUsableHeaderFallsBackToRemoteAddr(): void
    {
        self::assertSame(self::EDGE, self::clientIp(self::EDGE, self::INNER));
    }

    public function testASingleHopIsTheClient(): void
    {
        self::assertSame('203.0.113.9', self::clientIp(self::EDGE, '203.0.113.9'));
    }

    public function testAnUntrustedRemoteAddrIgnoresEveryHeader(): void
    {
        self::assertSame('192.0.2.50', self::clientIp('192.0.2.50', '203.0.113.9', '198.51.100.7'));
    }

    public function testTheFilterAllowlistUsesTheSameRightWalk(): void
    {
        // Mode 2: no constant; the allowlist comes from the filter.
        if (!function_exists('apply_filters')) {
            eval('function apply_filters(string $hook, $value = null) { return $hook === "bcc_trusted_proxy_ips" ? ["10.0.0.1", "10.0.0.2"] : $value; }');
        }

        self::assertSame('203.0.113.9', self::clientIp(self::EDGE, '6.6.6.6, 203.0.113.9, ' . self::INNER));
        self::assertSame('192.0.2.50', self::clientIp('192.0.2.50', '203.0.113.9'), 'untrusted REMOTE_ADDR ignores headers');
    }
}
