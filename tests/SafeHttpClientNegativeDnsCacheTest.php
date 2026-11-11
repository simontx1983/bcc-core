<?php

declare(strict_types=1);

namespace BCC\Core\Http\Tests;

use BCC\Core\Http\SafeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/Stubs/dns-resolver-stub.php';

/**
 * F-156(a) — a FAILED DNS resolve is now remembered, so a retry does not
 * re-pay it.
 *
 * ⚠⚠ THE DEFECT, and it is the pathological case specifically. The
 * positive cache already existed, and its own comment says why: a burst
 * of calls to one host would otherwise "block on the OS resolver on
 * every call", and slow NXDOMAINs "become a worker-exhaustion vector".
 * But the cache was written ONLY ON SUCCESS — so the healthy path was
 * cached and the case that comment names re-paid the full resolve every
 * time. `ApiRetry` retries, so one synchronous user request could pay it
 * several times.
 *
 * ⚠ And the cost cannot be bounded from PHP. `gethostbyname()` takes NO
 * timeout argument and blocks on the OS resolver, whose own defaults
 * commonly reach ~30s and are not settable from PHP; `dns_get_record()`
 * is the same. A `timeout` in the request args bounds the HTTP exchange
 * only — the resolve happens before any request is made. Not repeating
 * the resolve is therefore the only lever available, which is why this
 * is a cache fix rather than a timeout fix.
 *
 * ⚠ Caching a SECURITY decision deserves the explicit argument: a cached
 * failure can only ever produce a REFUSAL, so it cannot weaken the SSRF
 * guard. The risk it does carry is the opposite one — briefly refusing a
 * host that has since started resolving publicly — which is the
 * fail-closed direction, and is why the negative TTL is far shorter than
 * the positive one. Both directions are asserted below.
 */
#[CoversClass(SafeHttpClient::class)]
final class SafeHttpClientNegativeDnsCacheTest extends TestCase
{
    private const HOST = 'nxdomain.invalid';

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

    /** The cache is private static and leaks across tests otherwise. */
    private static function clearDnsCache(): void
    {
        $p = (new ReflectionClass(SafeHttpClient::class))->getProperty('dnsCache');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    /** @return \WP_Error|array<string, mixed>|null */
    private static function validate(string $url)
    {
        $m = (new ReflectionClass(SafeHttpClient::class))->getMethod("validateAndPinUrl");
        $m->setAccessible(true);
        return $m->invoke(null, $url);
    }

    // ── the fix ─────────────────────────────────────────────────────

    /**
     * ⚠⚠ THE HEADLINE: a second attempt must NOT re-resolve. This is the
     * entire point — the resolve is unbounded, so the only protection is
     * not doing it again.
     */
    public function testAFailedResolveIsNotRepaidOnTheNextAttempt(): void
    {
        DnsStub::failAll();

        $first  = self::validate('https://' . self::HOST . '/x');
        $second = self::validate('https://' . self::HOST . '/y');

        $this->assertInstanceOf(\WP_Error::class, $first);
        $this->assertInstanceOf(\WP_Error::class, $second);
        $this->assertSame(
            1,
            count(DnsStub::$lookups),
            'the second call must be served from the negative cache, not re-resolved'
        );
    }

    /**
     * ⚠ And the replayed refusal must be the SAME refusal. A cached
     * failure that answered a different code would make the cache
     * observable to callers and could route them down another path.
     */
    public function testTheCachedRefusalIsIdenticalToTheFirstOne(): void
    {
        DnsStub::failAll();

        $first  = self::validate('https://' . self::HOST . '/x');
        $second = self::validate('https://' . self::HOST . '/y');

        $this->assertInstanceOf(\WP_Error::class, $first);
        $this->assertInstanceOf(\WP_Error::class, $second);
        $this->assertSame($first->get_error_code(), $second->get_error_code());
        $this->assertSame('ssrf_blocked', $second->get_error_code());
    }

    // ── it must not weaken the guard ────────────────────────────────

    /**
     * ⚠⚠ The safety property that makes caching a security decision
     * acceptable: a cached failure produces a REFUSAL, never an
     * allowance. Asserted directly rather than argued in a comment.
     */
    public function testACachedFailureNeverBecomesAnAllowance(): void
    {
        DnsStub::failAll();
        $this->assertInstanceOf(\WP_Error::class, self::validate('https://' . self::HOST . '/a'));

        // DNS "recovers" mid-request — but the entry is still cached, so
        // the request is still refused. Fail-CLOSED, deliberately.
        DnsStub::succeedWith('93.184.216.34');

        $out = self::validate('https://' . self::HOST . '/b');
        $this->assertInstanceOf(
            \WP_Error::class,
            $out,
            'while the negative entry is live the host stays refused — the safe direction'
        );
    }

    /**
     * ⚠ Anti-vacuity: every case above refuses, so a validator that
     * refused EVERYTHING would pass them all. A host that resolves
     * publicly must still be allowed, and must be PINNED to the
     * resolved IP.
     */
    public function testAPubliclyResolvingHostIsStillAllowedAndPinned(): void
    {
        DnsStub::succeedWith('93.184.216.34');

        $out = self::validate('https://example.test/x');

        $this->assertIsArray($out);
        $this->assertSame('93.184.216.34', $out['ip']);
        $this->assertSame('example.test', $out['host']);
        $this->assertSame(443, $out['port']);
    }

    /** And the positive path is cached too, as it already was. */
    public function testASuccessfulResolveIsStillCached(): void
    {
        DnsStub::succeedWith('93.184.216.34');

        self::validate('https://example.test/x');
        self::validate('https://example.test/y');

        $this->assertSame(1, count(DnsStub::$lookups), 'the positive cache must still work');
    }

    // ── the TTLs ────────────────────────────────────────────────────

    /**
     * ⚠ The negative TTL must be SHORTER than the positive one. A cached
     * failure denies a legitimate request, so it should expire quickly
     * once DNS recovers; a cached success is only a pin. If these ever
     * converge, a transient NXDOMAIN would block a host for a full
     * minute.
     */
    public function testTheNegativeTtlIsShorterThanThePositiveOne(): void
    {
        $r   = new ReflectionClass(SafeHttpClient::class);
        $neg = $r->getConstant('DNS_NEGATIVE_CACHE_TTL');
        $pos = $r->getConstant('DNS_CACHE_TTL');

        $this->assertIsInt($neg);
        $this->assertIsInt($pos);
        $this->assertGreaterThan(0, $neg, 'a zero TTL would disable the fix entirely');
        $this->assertLessThan($pos, $neg);
    }

    /**
     * ⚠ The stub must be INERT when disabled, or it would silently
     * intercept every sibling test in this namespace — the reason it is
     * switchable rather than a bare shadow. Proved by behaviour: with
     * interception off, a lookup is not recorded.
     */
    public function testTheStubIsInertWhenDisabled(): void
    {
        DnsStub::reset();

        // An IP literal never reaches DNS, so this is safe and offline;
        // what matters is that the stub recorded nothing.
        self::validate('https://93.184.216.34/x');

        $this->assertFalse(DnsStub::$enabled);
        $this->assertSame([], DnsStub::$lookups);
    }
}
