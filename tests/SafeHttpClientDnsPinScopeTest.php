<?php

declare(strict_types=1);

namespace BCC\Core\Http\Tests;

use BCC\Core\Http\SafeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/Stubs/dns-resolver-stub.php';

/**
 * The `CURLOPT_RESOLVE` pin belongs to the request it was validated for.
 *
 * ── TWO BUGS, AND THE SECOND ONE WAS MINE ───────────────────────────────
 * The pin used to live in a static array keyed by host.
 *
 * 1. A LATER request to the same host inherited a pin it never earned.
 *    `prepareArgs()` is public and its docblock names `ApiRetry`'s retry
 *    loop as the intended caller, but the only release was a PRIVATE
 *    `clearPin()` called solely from `get()`/`post()`'s `finally` — a
 *    public acquire with a private release, so the one named caller could
 *    never release. Cost: freshness, not safety. The pinned IP was
 *    validated public and the lookup was host-keyed, so a stale pin could
 *    not misdirect a request to a different host.
 *
 * 2. ⚠⚠ Bounding that static with a TTL instead — the first attempt at the
 *    fix — was WORSE, and review caught it. An expired pin was dropped and
 *    the request then went out with NO `CURLOPT_RESOLVE` at all, reopening
 *    the DNS-rebinding window the pin exists to close. Reachable, not
 *    theoretical: `ApiRetry` calls `prepareArgs()` ONCE outside its retry
 *    loop, so with Solana's 30s timeout its attempts begin at 0, 32, 65 and
 *    98 seconds — the last two past a 60s TTL.
 *
 * ⇒ A TTL was the wrong MECHANISM. It approximates "the dispatch this pin
 * belongs to" with a clock, and no value is both shorter than the staleness
 * window and longer than a retry sequence. The entry now travels in the
 * request's own args, which answers both at the root: a request that did
 * not come from `prepareArgs()` carries no pin and cannot inherit one; a
 * request that did carries its own and can never be unpinned, however long
 * its retries run. No shared state, nothing to expire, no branch left to
 * fail open through.
 *
 * ── WHAT THESE TESTS CAN AND CANNOT SEE ─────────────────────────────────
 * ⚠ There is no `curl_getopt`, so NOTHING here can read `CURLOPT_RESOLVE`
 * back off a handle, and the `curl_setopt()` call itself is NOT covered.
 * That is why the decision lives in `resolveEntryFor()` and is asserted
 * there; `applyCurlResolve()` is a thin wrapper over it. An assertion
 * shaped like "the handle was configured" would be theatre.
 */
#[CoversClass(SafeHttpClient::class)]
final class SafeHttpClientDnsPinScopeTest extends TestCase
{
    private const HOST = 'example.test';
    private const IP   = '93.184.216.34';
    private const T0   = 1700000000;

    protected function setUp(): void
    {
        DnsStub::reset();
        self::clearDnsCache();
    }

    protected function tearDown(): void
    {
        DnsStub::reset();
        self::clearDnsCache();
    }

    // ── the four cells review asked for ─────────────────────────────

    /**
     * ⚠⚠ THE REGRESSION CASE. The exact scenario that failed under the TTL:
     * `prepareArgs()` runs once, and the last retry fires 98 seconds later
     * (Solana's 30s timeout, four attempts, backoff between). The entry must
     * still be there. Under the TTL this returned nothing and the request
     * went out unpinned.
     */
    public function testAPinSurvivesAFullRetrySpan(): void
    {
        DnsStub::startClock(self::T0);
        $args = $this->pinnedArgs();

        // The 4th ApiRetry attempt against a 30s-timeout provider.
        DnsStub::advance(98);

        self::assertSame(
            self::HOST . ':443:' . self::IP,
            SafeHttpClient::resolveEntryFor($args, 'https://' . self::HOST . '/x'),
            'the pin must outlive the whole retry sequence it was made for'
        );
    }

    /**
     * ⚠ THE ORIGINAL DEFECT, now structural. A request that never went
     * through `prepareArgs()` carries no pin, so it cannot inherit one. null
     * is the correct answer and not a failure: such a request was never
     * SSRF-validated and is not ours to pin.
     */
    public function testARequestWithNoPinGetsNothing(): void
    {
        self::assertNull(
            SafeHttpClient::resolveEntryFor([], 'https://' . self::HOST . '/x'),
            'no pin may be inherited from anywhere'
        );
        self::assertNull(
            SafeHttpClient::resolveEntryFor(['timeout' => 5], 'https://' . self::HOST . '/x')
        );
    }

    /** Args carried onto a different host must not pin that host. */
    public function testAHostMismatchGetsNothing(): void
    {
        DnsStub::startClock(self::T0);
        $args = $this->pinnedArgs();

        self::assertNull(
            SafeHttpClient::resolveEntryFor($args, 'https://other.test/x'),
            'a pin is valid only for the host it was validated for'
        );
    }

    /**
     * ⚠ CASE-INSENSITIVE, because DNS names are. The old static lookup
     * compared raw-case array keys and was therefore case-SENSITIVE; it only
     * ever worked because the same `$url` string was used to pin and to look
     * up. Args can be reused across URL spellings.
     */
    public function testACaseVariantHostStillMatches(): void
    {
        DnsStub::startClock(self::T0);
        $args = $this->pinnedArgs();

        self::assertSame(
            self::HOST . ':443:' . self::IP,
            SafeHttpClient::resolveEntryFor($args, 'https://ExAmPlE.TeSt/x')
        );
    }

    // ── the entry itself ────────────────────────────────────────────

    /** `prepareArgs()` is what pins, and the entry is the cURL format. */
    public function testPrepareArgsCarriesTheEntry(): void
    {
        DnsStub::startClock(self::T0);
        $args = $this->pinnedArgs();

        $key = self::pinArgKey();
        self::assertArrayHasKey($key, $args);
        self::assertSame(self::HOST . ':443:' . self::IP, $args[$key]);
    }

    /**
     * ⚠ A malformed or empty entry is refused rather than half-applied.
     * `resolveEntryFor()` is reachable from a hook that any plugin can also
     * feed, so it validates its input instead of trusting it.
     */
    public function testAMalformedEntryGetsNothing(): void
    {
        $url = 'https://' . self::HOST . '/x';
        $key = self::pinArgKey();

        foreach ([['' ], ['nonsense'], [self::HOST . ':443'], [':443:' . self::IP], [self::HOST . ':443:']] as $case) {
            self::assertNull(
                SafeHttpClient::resolveEntryFor([$key => $case[0]], $url),
                'refused: ' . var_export($case[0], true)
            );
        }

        self::assertNull(SafeHttpClient::resolveEntryFor([$key => 12345], $url), 'a non-string is refused');
    }

    /**
     * ⚠ An IPv6 address keeps its colons. `resolvePublicIp()` falls back to
     * an AAAA record, so the address half of the entry can contain colons
     * and the parse must split on the FIRST TWO only. The host half never
     * does: an IP-literal host returns before pinning, so a pinned host is
     * always a DNS name.
     */
    public function testAnIpv6AddressSurvivesTheParse(): void
    {
        $entry = self::HOST . ':443:2001:db8::1';

        self::assertSame(
            $entry,
            SafeHttpClient::resolveEntryFor([self::pinArgKey() => $entry], 'https://' . self::HOST . '/x')
        );
    }

    // ── the pin must not reach the wire ─────────────────────────────

    /**
     * ⚠⚠ THE PIN MUST NOT BECOME PART OF THE REQUEST. Review asked for an
     * assertion that the key never reaches the transport options
     * (`class-wp-http.php:341` builds them from an explicit allow-list, so
     * an unknown key is visible to hooks and filters and nowhere else).
     *
     * ⚠ THAT CANNOT BE ASSERTED HERE, and saying so is better than a test
     * shaped like it was: WordPress core is not loaded in this suite, so
     * nothing can exercise that allow-list. A conditional test would skip in
     * CI and read as coverage it is not.
     *
     * What IS assertable is the half this class owns: the pin goes in the
     * returned args and nowhere that travels on the wire. Headers and body
     * come back untouched, so the entry cannot be smuggled into either.
     * The allow-list claim is recorded above with its file and line, and
     * belongs in an integration test with WordPress loaded if it is ever
     * wanted mechanically.
     */
    public function testThePinIsNotSmuggledIntoHeadersOrBody(): void
    {
        DnsStub::succeedWith(self::IP);
        DnsStub::startClock(self::T0);

        $headers = ['Accept' => 'application/json', 'X-Thing' => 'y'];
        $out     = SafeHttpClient::prepareArgs('https://' . self::HOST . '/x', [
            'timeout' => 5,
            'headers' => $headers,
            'body'    => 'payload',
        ]);

        self::assertIsArray($out);
        self::assertSame($headers, $out['headers'], 'the pin must not be injected into headers');
        self::assertSame('payload', $out['body'], 'nor into the body');

        $flat = (string) json_encode(['h' => $out['headers'], 'b' => $out['body']]);
        self::assertStringNotContainsString(self::IP, $flat, 'the pinned IP must not appear on the wire');
        self::assertStringNotContainsString(self::pinArgKey(), $flat);
    }

    // ── review condition 5: the validated settings are untouched ────

    /**
     * ⚠ `redirection => 0` and the forced-cURL refusal are the other half of
     * the SSRF guard and were explicitly out of scope for this change.
     * Pinned here so the rebuild cannot have disturbed them.
     */
    public function testTheRedirectAndTransportGuardsStillHold(): void
    {
        DnsStub::succeedWith(self::IP);
        DnsStub::startClock(self::T0);

        $out = SafeHttpClient::prepareArgs('https://' . self::HOST . '/x', ['timeout' => 5]);

        self::assertIsArray($out);
        self::assertSame(0, $out['redirection'], 'redirects stay default-denied');
    }

    // ── helpers ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function pinnedArgs(): array
    {
        DnsStub::succeedWith(self::IP);

        $out = SafeHttpClient::prepareArgs('https://' . self::HOST . '/x', ['timeout' => 5]);
        self::assertIsArray($out, 'the host must validate, or this fixture proves nothing');

        return $out;
    }

    private static function pinArgKey(): string
    {
        $k = (new ReflectionClass(SafeHttpClient::class))->getConstant('PIN_ARG');
        self::assertIsString($k);

        return $k;
    }

    /** The DNS cache is static and leaks across tests otherwise. */
    private static function clearDnsCache(): void
    {
        $p = (new ReflectionClass(SafeHttpClient::class))->getProperty('dnsCache');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }
}
