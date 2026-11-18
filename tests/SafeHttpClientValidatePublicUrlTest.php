<?php

declare(strict_types=1);

namespace BCC\Core\Http\Tests;

use BCC\Core\Http\SafeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
     * CGNAT and the benchmark range, at the URL level.
     *
     * ⚠ NO LONGER SKIPPED BELOW PHP 8.2. The first round applied
     * `FILTER_FLAG_GLOBAL_RANGE` when present and skipped this test
     * otherwise, which left these two ranges open on a PHP 8.0/8.1 host —
     * and the plugin declares 8.0. They are now denied explicitly by CIDR
     * on every version, so the assertion is unconditional and the skip
     * would have been hiding a real gap rather than documenting one.
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
    public function testCgnatAndBenchmarkIpv4AreBlockedOnEveryVersion(string $url): void
    {
        $result = SafeHttpClient::validatePublicUrl($url);

        self::assertInstanceOf(WP_Error::class, $result, "expected block for {$url}");
        self::assertSame('ssrf_blocked', $result->get_error_code(), "wrong code for {$url}");
    }

    /**
     * ⚠⚠ THE HELPER ITSELF, because the REACHABLE path cannot be reached
     * from a URL in a unit test.
     *
     * The exploitable site is `resolvePublicIp()`: an attacker who controls
     * DNS for their own name answers with an AAAA record of
     * `64:ff9b::a9fe:a9fe`, it satisfies the range flags, and it is then
     * PINNED as the connect target — so the pin that exists to defeat DNS
     * rebinding instead guarantees the request lands on the metadata
     * endpoint. That method calls `gethostbyname()` / `dns_get_record()`
     * directly with no injectable seam, so a network-free test cannot drive
     * it, and the tests above reach only the URL-literal site.
     *
     * What makes those two sites agree is that both now ask `isPublicIp()`
     * and nothing else. Pinning the helper's verdicts therefore covers the
     * DNS site's decision; the one-line call there is all that is left
     * untested, and it is visible in the diff.
     *
     * ⚠ Addresses go in as BARE IPs here — no brackets. That is the form
     * `resolvePublicIp()` sees, since `$record['ipv6']` from
     * `dns_get_record()` is never bracketed.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function helperVerdicts(): array
    {
        return [
            // Embedded private/metadata IPv4 — all ALLOWED before this fix.
            '6to4 -> 10.0.0.1'            => ['2002:0a00:0001::1',    false],
            '6to4 -> metadata'            => ['2002:a9fe:a9fe::1',    false],
            'NAT64 /96 -> metadata'       => ['64:ff9b::a9fe:a9fe',   false],
            'NAT64 /48 -> 10.0.0.1'       => ['64:ff9b:1::0a00:0001', false],
            'IPv4-compatible -> 10.0.0.1' => ['::0a00:0001',          false],
            'IPv4-mapped -> metadata'     => ['::ffff:a9fe:a9fe',     false],

            // ⚠ SIIT ::ffff:0:0:0/96 is a DIFFERENT prefix from the mapped
            // ::ffff:0:0/96 above — `ffff` sits in bytes 8-9 rather than
            // 10-11. The first round refused the mapped form and admitted
            // this one, so both spellings are pinned.
            'SIIT -> metadata'            => ['::ffff:0:a9fe:a9fe',   false],
            'SIIT -> 10.0.0.1'            => ['::ffff:0:a00:1',       false],

            // ⚠⚠ RFC 8215 local-use NAT64, refused as a WHOLE /48. Its
            // IPv4 is split across bytes 6-8 and 9-11 (RFC 6052 §2.2), not
            // in the tail, so round one's twelve-byte compare matched only
            // the single /96 with zero middle bytes and let all of these
            // through. Each was measured as ALLOWED before this fix.
            'RFC8215 /48 scattered'       => ['64:ff9b:1:1234:5678:9abc:a00:1', false],
            'RFC8215 /48 layout 10.0.0.1' => ['64:ff9b:1:a00:0:100::', false],
            'RFC8215 /48 layout metadata' => ['64:ff9b:1:a9:fe00:a9fe::', false],
            'RFC8215 /48 abcd'            => ['64:ff9b:1:abcd::a00:1', false],
            'RFC8215 /48 bare prefix'     => ['64:ff9b:1::',          false],

            // Deprecated site-local (RFC 3879) — deprecated is not
            // unroutable, and the range flags still admit it.
            'site-local fec0::/10 low'    => ['fec0::1',              false],
            'site-local fec0::/10 high'   => ['feff::1',              false],

            // CGNAT + benchmark, denied explicitly rather than via
            // GLOBAL_RANGE, so the verdict no longer depends on PHP 8.2.
            'CGNAT 100.64/10 low'         => ['100.64.0.1',           false],
            'CGNAT 100.64/10 high'        => ['100.127.255.254',      false],
            'benchmark 198.18/15 low'     => ['198.18.0.1',           false],
            'benchmark 198.18/15 high'    => ['198.19.255.254',       false],

            // Already refused before the fix — must stay refused.
            'loopback v6'                 => ['::1',                  false],
            'ULA fc00::/7'                => ['fd00::1',              false],
            'metadata v4'                 => ['169.254.169.254',      false],
            'rfc1918 v4'                  => ['10.0.0.1',             false],
            'not an IP at all'            => ['nonsense',             false],
            // Public — must stay allowed, or link previews break.
            'public v4'                   => ['93.184.216.34',        true],
            'public v4 (resolver)'        => ['1.1.1.1',              true],
            'public v6'                   => ['2606:4700::1111',      true],
            'public v6 (other)'           => ['2001:4860:4860::8888', true],
            '6to4 wrapping a PUBLIC v4'   => ['2002:5db8:d822::1',    true],

            // ⚠⚠ BOUNDARY CONTROLS — these are what keep the new denials
            // from being quietly too wide. Without them, a /32 written
            // where a /48 was meant, or a mask off by one bit, would pass
            // every assertion above.
            'just outside the /48'        => ['64:ff9b:2::1',         true],
            'just below CGNAT'            => ['100.63.255.255',       true],
            'just below benchmark'        => ['198.17.255.255',       true],
            'just above benchmark'        => ['198.20.0.1',           true],
        ];
    }

    #[DataProvider('helperVerdicts')]
    public function testIsPublicIpVerdict(string $ip, bool $expected): void
    {
        $ref = new ReflectionMethod(SafeHttpClient::class, 'isPublicIp');
        $ref->setAccessible(true);

        self::assertSame(
            $expected,
            $ref->invoke(null, $ip),
            sprintf('%s should be judged %s', $ip, $expected ? 'public' : 'NOT public')
        );
    }

    /**
     * ⚠⚠ THE CIDR FALLBACK ON ITS OWN, because nothing else can evidence it
     * here.
     *
     * CGNAT and 198.18/15 are denied twice over on this runtime: by
     * `FILTER_FLAG_GLOBAL_RANGE` and by `matchesDeniedIpv4Range()`. So every
     * URL-level and `isPublicIp()` assertion about them passes on PHP 8.2+
     * EVEN WITHOUT the fallback — they would only fail on the 8.0/8.1 hosts
     * the fallback exists for, and no 8.0 runtime is available to test on.
     *
     * A mutation control confirmed exactly that: with the fallback reverted,
     * the four CGNAT/benchmark vectors still passed. Calling the fallback
     * directly is what makes the version-independent denial a tested claim
     * rather than a comment, and it is the only assertion in this file that
     * would catch the fallback being deleted.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function cidrFallbackVerdicts(): array
    {
        return [
            'CGNAT low'              => ['100.64.0.1',      true],
            'CGNAT high'             => ['100.127.255.254', true],
            'benchmark low'          => ['198.18.0.1',      true],
            'benchmark high'         => ['198.19.255.254',  true],
            // Boundaries — one bit out and these would be caught too.
            'just below CGNAT'       => ['100.63.255.255',  false],
            'just above CGNAT'       => ['100.128.0.0',     false],
            'just below benchmark'   => ['198.17.255.255',  false],
            'just above benchmark'   => ['198.20.0.1',      false],
            // Ordinary public space, and a v6 address it must ignore.
            'public v4'              => ['93.184.216.34',   false],
            'v6 is not its business' => ['2606:4700::1111', false],
        ];
    }

    #[DataProvider('cidrFallbackVerdicts')]
    public function testCidrFallbackDeniesIndependentlyOfGlobalRange(string $ip, bool $denied): void
    {
        $ref = new ReflectionMethod(SafeHttpClient::class, 'matchesDeniedIpv4Range');
        $ref->setAccessible(true);

        self::assertSame(
            $denied,
            $ref->invoke(null, $ip),
            sprintf('%s should %sbe denied by the CIDR fallback', $ip, $denied ? '' : 'NOT ')
        );
    }
}
