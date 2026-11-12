<?php

declare(strict_types=1);

namespace BCC\Core\Security\Tests;

use BCC\Core\Security\Throttle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-126 — anonymous Throttle buckets keyed IPv6 at /64.
 *
 * One /48 (a site allocation, a free tunnel-broker account, a cheap VPS) is
 * 65,536 /64s, so an anonymous caller could rotate through that many fresh
 * buckets, each a new wp_options row. It is now /48, the same as bcc-trust's
 * RateLimiter. Pinned against the REAL Throttle::normalizeIpToSubnet():
 *   - every /64 inside one /48 shares one key; a different /48 does not;
 *   - an IPv4-mapped address (::ffff:a.b.c.d) keys on its IPv4 /24, never on
 *     the all-zero "::" every mapped client would otherwise share;
 *   - IPv4 stays /24; an invalid string passes through.
 */
final class ThrottleSubnetKeyTest extends TestCase
{
    private static function key(string $ip): string
    {
        $m = new \ReflectionMethod(Throttle::class, 'normalizeIpToSubnet');
        return (string) $m->invoke(null, $ip);
    }

    /** @return array<string, array{string, string}> */
    public static function keys(): array
    {
        return [
            'host in a /64'                 => ['2001:db8:abcd:12:1:2:3:4', '2001:db8:abcd::'],
            'another /64, same /48'         => ['2001:db8:abcd:ffff::1', '2001:db8:abcd::'],
            'the next /48'                  => ['2001:db8:abce::1', '2001:db8:abce::'],
            'loopback'                      => ['::1', '::'],
            'IPv4-mapped, dotted'           => ['::ffff:203.0.113.77', '203.0.113.0'],
            'IPv4-mapped, hex'              => ['::ffff:cb00:714d', '203.0.113.0'],
            'IPv4'                          => ['203.0.113.77', '203.0.113.0'],
            'invalid passes through'        => ['not-an-ip', 'not-an-ip'],
        ];
    }

    #[DataProvider('keys')]
    public function testTheKeyIsTheNetworkAnAbuserActuallyHolds(string $ip, string $expected): void
    {
        self::assertSame($expected, self::key($ip));
    }

    public function testRotatingAcrossSixtyFourSubnetsOfOneSlash48DoesNotYieldFreshBuckets(): void
    {
        $keys = [];
        foreach (['0', '1', 'ff', 'abcd', 'ffff'] as $subnetId) {
            $keys[] = self::key("2001:db8:1234:{$subnetId}::42");
        }

        self::assertSame(['2001:db8:1234::'], array_values(array_unique($keys)));
    }

    public function testTwoMappedIpv4ClientsOnDifferentNetworksDoNotShareABucket(): void
    {
        self::assertNotSame(self::key('::ffff:198.51.100.4'), self::key('::ffff:203.0.113.4'));
    }
}
