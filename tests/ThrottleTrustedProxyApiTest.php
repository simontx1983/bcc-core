<?php

declare(strict_types=1);

namespace BCC\Core\Security\Tests;

use BCC\Core\Security\Throttle;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-125 — the public trusted-proxy API bcc-trust's IpResolver delegates to.
 *
 * BCC_TRUSTED_PROXY_IPS / the bcc_trusted_proxy_ips filter were documented but
 * dead in production: every bcc-trust IP consumer (and the anonymous throttle
 * key) went through IpResolver, which never read them. Throttle now exposes
 * trustedProxies() and clientIp() so IpResolver can resolve through the one
 * resolver when — and only when — a proxy is declared. Pinned:
 *   - NO config (production): trustedProxies() is empty and clientIp() is
 *     exactly REMOTE_ADDR, even with forwarded and Cloudflare headers present;
 *   - the constant: parsed list; a request from a listed proxy resolves via
 *     its X-Forwarded-For, others stay REMOTE_ADDR;
 *   - the filter: string IP literals only; non-array ignored;
 *   - the constant wins over the filter.
 *
 * Subprocess-only: the constant is defined per test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ThrottleTrustedProxyApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The no-config path logs "proxy headers present without config" —
        // a no-op Logger (declared before anything autoloads the real one)
        // keeps this a pure resolver test with no WordPress filesystem.
        if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
            eval('namespace BCC\\Core\\Log; final class Logger {
                public static function warning(string $m, array $c = []): void {}
                public static function error(string $m, array $c = []): void {}
                public static function info(string $m, array $c = []): void {}
            }');
        }
        $GLOBALS['__bcc_proxy_filter'] = null;
        if (!function_exists('apply_filters')) {
            eval('function apply_filters(string $hook, $value = null) { return $hook === "bcc_trusted_proxy_ips" ? $GLOBALS["__bcc_proxy_filter"] : $value; }');
        }
        $_SERVER['REMOTE_ADDR']           = '192.0.2.50';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '6.6.6.6, 203.0.113.9';
        $_SERVER['HTTP_X_REAL_IP']        = '7.7.7.7';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.4.4';
    }

    public function testWithNoConfigTheClientIpIsExactlyRemoteAddr(): void
    {
        self::assertSame([], Throttle::trustedProxies());
        self::assertSame('192.0.2.50', Throttle::clientIp(), 'production: nothing changes');
    }

    public function testTheConstantIsParsedAndDrivesTheWalk(): void
    {
        define('BCC_TRUSTED_PROXY_IPS', ' 192.0.2.50 , 10.0.0.2');

        self::assertSame(['192.0.2.50', '10.0.0.2'], Throttle::trustedProxies());
        self::assertSame('203.0.113.9', Throttle::clientIp(), 'from a listed proxy: the rightmost untrusted hop');
    }

    public function testARequestNotFromAListedProxyStaysRemoteAddr(): void
    {
        define('BCC_TRUSTED_PROXY_IPS', '10.0.0.1');

        self::assertSame('192.0.2.50', Throttle::clientIp());
    }

    public function testTheFilterKeepsStringLiteralsOnly(): void
    {
        $GLOBALS['__bcc_proxy_filter'] = [' 192.0.2.50 ', 42, null, ''];

        self::assertSame(['192.0.2.50'], Throttle::trustedProxies());
        self::assertSame('203.0.113.9', Throttle::clientIp());
    }

    public function testANonArrayFilterIsIgnored(): void
    {
        $GLOBALS['__bcc_proxy_filter'] = '192.0.2.50';

        self::assertSame([], Throttle::trustedProxies());
        self::assertSame('192.0.2.50', Throttle::clientIp());
    }

    public function testTheConstantWinsOverTheFilter(): void
    {
        define('BCC_TRUSTED_PROXY_IPS', '10.0.0.9');
        $GLOBALS['__bcc_proxy_filter'] = ['192.0.2.50'];

        self::assertSame(['10.0.0.9'], Throttle::trustedProxies());
        self::assertSame('192.0.2.50', Throttle::clientIp(), 'REMOTE_ADDR is not in the constant list');
    }
}
