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

    /**
     * IPv6 forms that EMBED an IPv4, one case per CIDR.
     *
     * ⚠ Every address below was ALLOWED by the range flags before this fix,
     * including with `FILTER_FLAG_GLOBAL_RANGE` added — each is a
     * routable-looking IPv6 address whose embedded IPv4 is private or is the
     * cloud metadata endpoint, which is where a relay would deliver it.
     *
     * ⚠ The IPv4 tail is spelled in HEX on purpose. The dotted spelling
     * (`64:ff9b::10.0.0.1`) was already refused by `filter_var()` while the
     * hex spelling of the SAME SIXTEEN BYTES was admitted, so testing the
     * dotted form would pass without the fix and prove nothing.
     *
     * @return array<string, array{0: string}>
     */
    public static function embeddedIpv4Urls(): array
    {
        return [
            '6to4 2002::/16 -> 10.0.0.1'          => ['http://[2002:0a00:0001::1]/x'],
            '6to4 2002::/16 -> metadata'          => ['http://[2002:a9fe:a9fe::1]/latest/meta-data/'],
            '6to4 2002::/16 -> loopback'          => ['http://[2002:7f00:0001::1]/x'],
            'NAT64 64:ff9b::/96 -> 10.0.0.1'      => ['http://[64:ff9b::0a00:0001]/x'],
            'NAT64 64:ff9b::/96 -> metadata'      => ['http://[64:ff9b::a9fe:a9fe]/x'],
            'NAT64 64:ff9b:1::/48 -> 10.0.0.1'    => ['http://[64:ff9b:1::0a00:0001]/x'],
            'IPv4-compatible ::/96 -> 10.0.0.1'   => ['http://[::0a00:0001]/x'],
            'IPv4-mapped ::ffff:0:0/96 -> meta'   => ['http://[::ffff:a9fe:a9fe]/x'],
        ];
    }

    /**
     * ⚠⚠ ASSERTS THE MESSAGE, NOT JUST THE CODE — deliberately.
     *
     * `ssrf_blocked` is returned by two different paths: the IP-literal
     * range check ("private/reserved IP") and the DNS path ("resolves to no
     * public IP addresses"). A bracketed IPv6 host used to reach the SECOND
     * one, because `parse_url()` keeps the brackets and
     * `FILTER_VALIDATE_IP` refuses "[::1]" — so a code-only assertion
     * passes whether or not the literal check ever ran. That is precisely
     * how this file's pre-existing `loopback v6` case passed while the IPv6
     * literal branch was unreachable.
     */
    #[DataProvider('embeddedIpv4Urls')]
    public function testIpv6CarryingAPrivateIpv4IsBlockedByTheLiteralCheck(string $url): void
    {
        $result = SafeHttpClient::validatePublicUrl($url);

        self::assertInstanceOf(WP_Error::class, $result, "expected block for {$url}");
        self::assertSame('ssrf_blocked', $result->get_error_code(), "wrong code for {$url}");
        self::assertStringContainsString(
            'private/reserved IP',
            (string) $result->get_error_message(),
            "{$url} was refused, but by the DNS path rather than the literal check"
        );
    }

    /**
     * Anti-vacuity: the fix must not blanket-block the prefixes it inspects.
     *
     * A 6to4 address wrapping a PUBLIC IPv4 is a legitimate public address,
     * and so is an ordinary global IPv6 literal. If these were refused, the
     * tests above would pass for a helper that simply rejected every IPv6
     * address — which is the easy wrong implementation here.
     *
     * ⚠ The plain `2606:4700::1111` case also pins the bracket fix: before
     * it, EVERY bracketed IPv6 literal fell through to the DNS path and was
     * refused, public ones included.
     *
     * @return array<string, array{0: string}>
     */
    public static function allowedIpv6Urls(): array
    {
        return [
            'public v6 literal'         => ['https://[2606:4700::1111]/x'],
            'public v6 literal (other)' => ['https://[2001:4860:4860::8888]/x'],
            '6to4 wrapping a PUBLIC v4' => ['https://[2002:5db8:d822::1]/x'],
        ];
    }

    #[DataProvider('allowedIpv6Urls')]
    public function testPublicIpv6LiteralIsAllowed(string $url): void
    {
        self::assertNull(
            SafeHttpClient::validatePublicUrl($url),
            "{$url} is public and must not be refused"
        );
    }

    /**
     * Brackets are legal around an IPv6 literal and nothing else, so a
     * bracketed non-IP is a malformed URL — not a hostname to go and
     * resolve. Pinned so the unwrap cannot later be loosened into handing
     * the DNS path a name the URL never legally contained.
     */
    public function testBracketedNonIpHostIsRejectedAsMalformed(): void
    {
        $result = SafeHttpClient::validatePublicUrl('http://[evil.example.com]/x');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('ssrf_invalid_url', $result->get_error_code());
    }

    /**
     * The IPv4 ranges only `FILTER_FLAG_GLOBAL_RANGE` knows about.
     *
     * ⚠ SKIPPED BELOW PHP 8.2, where the constant does not exist. The
     * plugin declares PHP 8.0, so `isPublicIp()` applies the flag only when
     * present and these ranges stay allowed on an older runtime — asserted
     * as a skip rather than quietly omitted, so the gap is visible in the
     * run output instead of being invisible.
     *
     * @return array<string, array{0: string}>
     */
    public static function globalRangeOnlyUrls(): array
    {
        return [
            'CGNAT 100.64/10 (RFC 6598)'     => ['http://100.64.0.1/x'],
            'CGNAT 100.64/10 upper'          => ['http://100.127.255.254/x'],
            'benchmark 198.18/15 (RFC 2544)' => ['http://198.18.0.1/x'],
        ];
    }

    #[DataProvider('globalRangeOnlyUrls')]
    public function testGlobalRangeOnlyIpv4IsBlockedWhereSupported(string $url): void
    {
        if (!defined('FILTER_FLAG_GLOBAL_RANGE')) {
            self::markTestSkipped('FILTER_FLAG_GLOBAL_RANGE requires PHP 8.2+');
        }

        $result = SafeHttpClient::validatePublicUrl($url);

        self::assertInstanceOf(WP_Error::class, $result, "expected block for {$url}");
        self::assertSame('ssrf_blocked', $result->get_error_code(), "wrong code for {$url}");
    }
}
