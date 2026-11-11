<?php

declare(strict_types=1);

namespace BCC\Core\Http\Tests;

use BCC\Core\Http\SafeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/Stubs/dns-resolver-stub.php';

/**
 * The `CURLOPT_RESOLVE` DNS pin now expires.
 *
 * ⚠⚠ THE DEFECT WAS AN API SHAPE, NOT A SLIP. `prepareArgs()` is public and
 * its own docblock names `ApiRetry`'s retry loop as the intended caller —
 * but the only release, `clearPin()`, is private and is called solely from
 * `SafeHttpClient::get()`/`post()`'s `finally`. So a public acquire had a
 * private release, and every caller that dispatches the request itself
 * (`ApiRetry::get()` and `ApiRetry::post()` — nearly every onchain HTTP
 * call) set a pin that nothing ever removed.
 *
 * ⚠ WHAT IT ACTUALLY COST, stated accurately rather than alarmingly. The
 * pin is keyed by host and `applyCurlResolve()` looks it up by the outgoing
 * request's OWN host, so a stale pin cannot misdirect a request to a
 * different host; and the pinned IP was validated public when it was set,
 * so it cannot point anywhere private. This is NOT an SSRF bypass. The cost
 * is freshness: a later request to the same host was forced onto a
 * possibly-rotated or dead IP instead of re-resolving. Scope is one PHP
 * request — which for WP-Cron and WP-CLI is a whole tick, and those are
 * where this class is used hardest.
 *
 * ⭐ The expiry closes it for EVERY `prepareArgs()` caller, present and
 * future, without the consuming repository changing anything — which
 * matters because this class ships separately from its callers.
 *
 * ── WHAT THESE TESTS CAN AND CANNOT SEE ─────────────────────────────────
 * ⚠ There is no `curl_getopt`, so NOTHING here can read `CURLOPT_RESOLVE`
 * back off a handle. These assertions observe the pin STATE and the exact
 * entry string that would be handed to cURL. The `curl_setopt()` call
 * itself is not covered, and this file does not pretend otherwise — an
 * assertion shaped like "the handle was configured" would be theatre.
 *
 * The clock is driven, not waited on: `dns-resolver-stub.php` shadows
 * `time()` for `BCC\Core\Http` via PHP's namespace fallback. The TTL is
 * 60s, so asserting expiry against the wall clock would mean a test that
 * really waits a minute.
 */
#[CoversClass(SafeHttpClient::class)]
final class SafeHttpClientDnsPinExpiryTest extends TestCase
{
    private const HOST = 'example.test';
    private const IP   = '93.184.216.34';
    private const T0   = 1700000000;

    protected function setUp(): void
    {
        DnsStub::reset();
        self::setPins([]);
        self::clearDnsCache();
    }

    protected function tearDown(): void
    {
        DnsStub::reset();
        self::setPins([]);
        self::clearDnsCache();
    }

    // ── the pin carries an expiry ────────────────────────────────────

    /** A pin set through the real public seam records when it dies. */
    public function testAPinIsStoredWithAnExpiry(): void
    {
        $this->pinHost();

        $pins = self::pins();

        self::assertArrayHasKey(self::HOST, $pins);
        self::assertSame(self::HOST . ':443:' . self::IP, $pins[self::HOST]['entry']);
        self::assertSame(self::T0 + self::ttl(), $pins[self::HOST]['expires']);
    }

    /**
     * ⚠ The TTL is the EXISTING `DNS_CACHE_TTL`, not a second number
     * invented for the pin. The pin encodes a resolution, so it may live as
     * long as that resolution is allowed to be believed; a separate constant
     * would be a second thing to keep in step with the first.
     */
    public function testTheExpiryReusesTheDnsCacheTtl(): void
    {
        $this->pinHost();

        self::assertSame(
            self::T0 + self::ttl(),
            self::pins()[self::HOST]['expires'],
            'the pin TTL must be DNS_CACHE_TTL, not a parallel constant'
        );
        self::assertGreaterThan(0, self::ttl(), 'a zero TTL would disable pinning entirely');
    }

    // ── it expires ───────────────────────────────────────────────────

    /**
     * ⚠⚠ THE HEADLINE. Past its TTL the pin is gone, so the next request to
     * this host re-resolves instead of being forced onto the old IP. This is
     * the whole fix: before it, nothing removed the entry for the callers
     * that cannot call `clearPin()`.
     */
    public function testAnExpiredPinIsDroppedOnRead(): void
    {
        $this->pinHost();

        DnsStub::advance(self::ttl() + 1);
        self::readPin();

        self::assertArrayNotHasKey(
            self::HOST,
            self::pins(),
            'an expired pin must be dropped, not merely ignored'
        );
    }

    /**
     * ⚠ ANTI-VACUITY for the case above: an implementation that dropped the
     * pin unconditionally would pass it. Inside the TTL the pin must SURVIVE
     * a read — that is what makes retries of the same logical request reuse
     * one validated IP, which is the reason the pin exists.
     */
    public function testALivePinSurvivesARead(): void
    {
        $this->pinHost();

        DnsStub::advance(self::ttl() - 1);
        self::readPin();

        self::assertArrayHasKey(self::HOST, self::pins());
        self::assertSame(self::HOST . ':443:' . self::IP, self::pins()[self::HOST]['entry']);
    }

    /** The boundary, asserted on the dying side as well as the living one. */
    public function testThePinDiesExactlyAtItsExpiry(): void
    {
        $this->pinHost();

        DnsStub::advance(self::ttl());
        self::readPin();

        self::assertArrayNotHasKey(
            self::HOST,
            self::pins(),
            'expires <= now is expired; a pin must not outlive its own instant'
        );
    }

    // ── the ordering decision ────────────────────────────────────────

    /**
     * ⚠ THE EXPIRY IS CHECKED BEFORE THE `instanceof CurlHandle` GUARD, AND
     * THIS IS THE CASE THAT PINS THAT ORDER.
     *
     * ⚠ Stated precisely, because the obvious justification is wrong: this
     * is NOT about the streams transport. The callback is hooked on
     * `http_api_curl`, which only the cURL transport fires, and
     * `prepareArgs()` refuses outright without cURL and disables streams
     * besides — so a pinned request is always a cURL request. The guard
     * exists for a handle this class does not control: another listener on
     * the same hook may replace it, and before PHP 8 a cURL handle was a
     * `resource`, not a `CurlHandle`. Checking expiry first keeps "expired
     * means gone" true on every path that reads the pin, rather than making
     * the lifetime bound conditional on the handle's type.
     */
    public function testAnExpiredPinIsDroppedEvenWithoutACurlHandle(): void
    {
        $this->pinHost();

        DnsStub::advance(self::ttl() + 1);

        $notAHandle = 'not a curl handle';
        SafeHttpClient::applyCurlResolve($notAHandle, [], 'https://' . self::HOST . '/x');

        self::assertArrayNotHasKey(
            self::HOST,
            self::pins(),
            'expiry must not be conditional on the transport reading it'
        );
    }

    /**
     * ⚠ ANTI-VACUITY for the case above: a non-cURL read must not drop a
     * LIVE pin either. Otherwise one streams-transport request in the middle
     * of a retry sequence would silently unpin the rest of it.
     */
    public function testALivePinIsLeftAloneWithoutACurlHandle(): void
    {
        $this->pinHost();

        $notAHandle = 'not a curl handle';
        SafeHttpClient::applyCurlResolve($notAHandle, [], 'https://' . self::HOST . '/x');

        self::assertArrayHasKey(self::HOST, self::pins());
    }

    // ── it stays host-scoped ─────────────────────────────────────────

    /**
     * ⚠ Reading one host must not disturb another's pin. This is also the
     * property that makes a stale pin harmless across hosts, and it is the
     * reason this defect is a freshness bug rather than an SSRF one — so it
     * is asserted rather than left as prose.
     */
    public function testReadingOneHostDoesNotDisturbAnother(): void
    {
        $this->pinHost();
        self::setPins(self::pins() + [
            'other.test' => ['entry' => 'other.test:443:198.51.100.7', 'expires' => self::T0 + 999],
        ]);

        DnsStub::advance(self::ttl() + 1);
        self::readPin();

        self::assertArrayNotHasKey(self::HOST, self::pins(), 'the read host expired…');
        self::assertArrayHasKey('other.test', self::pins(), '…and the other host was untouched');
    }

    // ── re-validation refreshes it ───────────────────────────────────

    /**
     * Re-pinning resets the expiry, which is correct: a caller reaching
     * `injectCurlResolve()` has just re-validated the host through
     * `validateAndPinUrl()`, so the new entry rests on fresh evidence.
     */
    public function testRePinningRefreshesTheExpiry(): void
    {
        $this->pinHost();
        $first = self::pins()[self::HOST]['expires'];

        DnsStub::advance(30);
        $this->pinHost();

        self::assertSame(
            $first + 30,
            self::pins()[self::HOST]['expires'],
            're-validation must extend the pin, not inherit the old deadline'
        );
    }

    // ── the prompt path still works ──────────────────────────────────

    /**
     * ⚠ The expiry is a BACKSTOP, not a replacement. `get()`/`post()` still
     * clear promptly in their `finally`, and that path must keep working
     * against the new entry shape — a pin that lingered for 60s after a
     * request that could have cleared it immediately would be a regression
     * for the callers that were never broken.
     */
    public function testClearPinStillRemovesTheEntry(): void
    {
        $this->pinHost();

        $m = (new ReflectionClass(SafeHttpClient::class))->getMethod('clearPin');
        $m->setAccessible(true);
        $m->invoke(null, self::HOST);

        self::assertSame([], self::pins());
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** Pin through the real public seam, not by writing the array. */
    private function pinHost(): void
    {
        DnsStub::succeedWith(self::IP);
        if (DnsStub::$now === 0) {
            DnsStub::startClock(self::T0);
        }

        $out = SafeHttpClient::prepareArgs('https://' . self::HOST . '/x', ['timeout' => 5]);

        self::assertIsArray($out, 'the host must validate, or this fixture proves nothing');
    }

    /** Drive one read of the pin through the real hook callback. */
    private static function readPin(): void
    {
        $handle = null;
        if (function_exists('curl_init')) {
            $handle = curl_init();
        }

        SafeHttpClient::applyCurlResolve($handle, [], 'https://' . self::HOST . '/x');

        if ($handle instanceof \CurlHandle) {
            curl_close($handle);
        }
    }

    private static function ttl(): int
    {
        $ttl = (new ReflectionClass(SafeHttpClient::class))->getConstant('DNS_CACHE_TTL');
        self::assertIsInt($ttl);

        return $ttl;
    }

    /** @return array<string, array{entry: string, expires: int}> */
    private static function pins(): array
    {
        $p = (new ReflectionClass(SafeHttpClient::class))->getProperty('pinnedResolves');
        $p->setAccessible(true);

        /** @var array<string, array{entry: string, expires: int}> $v */
        $v = $p->getValue();

        return $v;
    }

    /** @param array<string, array{entry: string, expires: int}> $pins */
    private static function setPins(array $pins): void
    {
        $p = (new ReflectionClass(SafeHttpClient::class))->getProperty('pinnedResolves');
        $p->setAccessible(true);
        $p->setValue(null, $pins);
    }

    /** The DNS cache is static too and leaks across tests otherwise. */
    private static function clearDnsCache(): void
    {
        $p = (new ReflectionClass(SafeHttpClient::class))->getProperty('dnsCache');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }
}
