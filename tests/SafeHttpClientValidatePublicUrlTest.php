<?php

declare(strict_types=1);

namespace BCC\Core\Http\Tests;

use BCC\Core\Http\SafeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * SafeHttpClient::validatePublicUrl() — the validate-only SSRF gate used by
 * callers whose transport is out of our hands (e.g. the bcc-trust web-push
 * endpoint, whose minishlink/web-push Guzzle client bypasses SafeHttpClient).
 *
 * Network-free: every case below is decided by scheme/URL parsing, the
 * blocked-host list, or an IP-literal range check — none reaches DNS. Public
 * IP literals return null (safe) without a lookup; private/reserved literals
 * and metadata hosts return a WP_Error. The hostname-DNS path is exercised by
 * the get()/post()/batch tests and is intentionally not re-covered here.
 */
#[CoversClass(SafeHttpClient::class)]
final class SafeHttpClientValidatePublicUrlTest extends TestCase
{
    public function testPublicIpLiteralIsAllowed(): void
    {
        // Public IP literal — no DNS, passes the private/reserved filter.
        self::assertNull(SafeHttpClient::validatePublicUrl('https://1.1.1.1/push/abc'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'loopback v4'     => ['http://127.0.0.1/x',                       'ssrf_blocked'],
            'rfc1918'         => ['http://10.0.0.5/x',                        'ssrf_blocked'],
            'rfc1918 172'     => ['https://172.16.9.9/x',                     'ssrf_blocked'],
            'link-local meta' => ['http://169.254.169.254/latest/meta-data/', 'ssrf_blocked'],
            'loopback v6'     => ['http://[::1]/x',                           'ssrf_blocked'],
            'metadata host'   => ['http://metadata.google.internal/',         'ssrf_blocked'],
            'bad scheme'      => ['ftp://1.1.1.1/x',                          'ssrf_invalid_scheme'],
            'no host'         => ['not-a-url',                                'ssrf_invalid_url'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testBlockedUrlReturnsError(string $url, string $expectedCode): void
    {
        $result = SafeHttpClient::validatePublicUrl($url);

        self::assertInstanceOf(WP_Error::class, $result, "expected block for {$url}");
        self::assertSame($expectedCode, $result->get_error_code(), "wrong code for {$url}");
    }
}
