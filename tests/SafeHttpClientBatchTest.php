<?php

declare(strict_types=1);

namespace BCC\Core\Http\Tests;

use BCC\Core\Http\SafeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

/**
 * SSRF invariants for the concurrent same-host batch primitive
 * SafeHttpClient::getBatchSameHost().
 *
 * These tests deliberately stay network-free. Every assertion exercises a
 * branch that returns BEFORE a socket is opened:
 *
 *   - empty input short-circuits to [];
 *   - a blocked/invalid SHARED host (first URL) fails the whole batch via the
 *     same validateAndPinUrl() gate the single-request path uses — no fetch;
 *   - the same-host-enforcement split (partitionByCanonicalHost) is pure and
 *     rejects mismatched/host-less URLs at their own index without poisoning
 *     the neighbours.
 *
 * The live curl_multi fetch loop itself is not covered here (it needs a real
 * upstream); see the class docblock / PR notes for that gap. The security-
 * relevant logic — validation, pinning inputs, same-host enforcement, index
 * alignment — is all reachable without the network and is covered below.
 */
#[CoversClass(SafeHttpClient::class)]
final class SafeHttpClientBatchTest extends TestCase
{
    /**
     * @param array<int, mixed> $args
     */
    private static function callPrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod(SafeHttpClient::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    // ---- empty input ------------------------------------------------------

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], SafeHttpClient::getBatchSameHost([]));
    }

    // ---- shared-host validation still applies (whole-batch reject) --------

    public function testInvalidSchemeSharedHostFailsWholeBatch(): void
    {
        $urls = ['ftp://example.com/a', 'ftp://example.com/b'];
        $out  = SafeHttpClient::getBatchSameHost($urls);

        self::assertCount(2, $out);
        foreach ($out as $i => $entry) {
            self::assertInstanceOf(WP_Error::class, $entry, "index {$i}");
            self::assertSame('ssrf_invalid_scheme', $entry->get_error_code(), "index {$i}");
        }
    }

    public function testPrivateIpSharedHostFailsWholeBatch(): void
    {
        $urls = ['http://127.0.0.1/x', 'http://127.0.0.1/y', 'http://127.0.0.1/z'];
        $out  = SafeHttpClient::getBatchSameHost($urls);

        self::assertCount(3, $out);
        foreach ($out as $i => $entry) {
            self::assertInstanceOf(WP_Error::class, $entry, "index {$i}");
            self::assertSame('ssrf_blocked', $entry->get_error_code(), "index {$i}");
        }
        // Index alignment: keys are exactly 0..2.
        self::assertSame([0, 1, 2], array_keys($out));
    }

    public function testMetadataHostSharedHostFailsWholeBatch(): void
    {
        $urls = ['http://metadata.google.internal/computeMetadata/v1/'];
        $out  = SafeHttpClient::getBatchSameHost($urls);

        self::assertCount(1, $out);
        self::assertInstanceOf(WP_Error::class, $out[0]);
        self::assertSame('ssrf_blocked', $out[0]->get_error_code());
    }

    public function testLinkLocalMetadataIpSharedHostBlocked(): void
    {
        // 169.254.169.254 is caught by FILTER_FLAG_NO_RES_RANGE on the IP path.
        $out = SafeHttpClient::getBatchSameHost(['http://169.254.169.254/latest/meta-data/']);
        self::assertInstanceOf(WP_Error::class, $out[0]);
        self::assertSame('ssrf_blocked', $out[0]->get_error_code());
    }

    // ---- same-host enforcement (pure partition) ---------------------------

    public function testPartitionRejectsMismatchedHostWithoutPoisoningOthers(): void
    {
        // Canonical host = cdn.example.com. The middle URL points at a DIFFERENT
        // host and must be rejected at its own index; the matching URLs stay
        // fetchable so a smuggled second host cannot ride the single validation.
        $urls = [
            'https://cdn.example.com/1.png',
            'https://evil.internal/secret',   // mismatch
            'https://cdn.example.com/2.png',
        ];

        /** @var array{0: array<int, string>, 1: array<int, WP_Error>} $out */
        $out = self::callPrivate('partitionByCanonicalHost', [$urls, 'cdn.example.com']);
        [$fetchable, $rejected] = $out;

        // Only the mismatched index is rejected.
        self::assertArrayHasKey(1, $rejected);
        self::assertInstanceOf(WP_Error::class, $rejected[1]);
        self::assertSame('ssrf_host_mismatch', $rejected[1]->get_error_code());

        // The two matching URLs survive at their ORIGINAL indexes (0 and 2).
        self::assertArrayHasKey(0, $fetchable);
        self::assertArrayHasKey(2, $fetchable);
        self::assertArrayNotHasKey(1, $fetchable);
        self::assertSame('https://cdn.example.com/1.png', $fetchable[0]);
        self::assertSame('https://cdn.example.com/2.png', $fetchable[2]);
    }

    public function testPartitionIsCaseInsensitiveOnHost(): void
    {
        $urls = ['https://CDN.Example.COM/a'];
        /** @var array{0: array<int, string>, 1: array<int, WP_Error>} $out */
        $out = self::callPrivate('partitionByCanonicalHost', [$urls, 'cdn.example.com']);
        [$fetchable, $rejected] = $out;

        self::assertArrayHasKey(0, $fetchable);
        self::assertSame([], $rejected);
    }

    public function testPartitionRejectsHostlessUrl(): void
    {
        $urls = ['not-a-url', 'https://cdn.example.com/ok'];
        /** @var array{0: array<int, string>, 1: array<int, WP_Error>} $out */
        $out = self::callPrivate('partitionByCanonicalHost', [$urls, 'cdn.example.com']);
        [$fetchable, $rejected] = $out;

        self::assertArrayHasKey(0, $rejected);
        self::assertSame('ssrf_invalid_url', $rejected[0]->get_error_code());
        self::assertArrayHasKey(1, $fetchable);
    }

    // ---- index alignment of the whole result set --------------------------

    public function testIndexAlignmentIsPreservedAcrossReject(): void
    {
        // First URL (canonical) is a private IP → whole batch fails, but the
        // result array must be index-aligned 0..N-1 with the input.
        $urls = [
            'http://10.0.0.1/a',
            'http://10.0.0.1/b',
            'http://10.0.0.1/c',
            'http://10.0.0.1/d',
        ];
        $out = SafeHttpClient::getBatchSameHost($urls);

        self::assertSame([0, 1, 2, 3], array_keys($out));
        self::assertCount(count($urls), $out);
    }

    // ---- arg normalisation helpers (pure) ---------------------------------

    public function testBatchTimeoutDefaultsAndCaps(): void
    {
        self::assertSame(3.0, self::callPrivate('batchTimeout', [[]]));
        self::assertSame(3.0, self::callPrivate('batchTimeout', [['timeout' => 0]]));
        self::assertSame(3.0, self::callPrivate('batchTimeout', [['timeout' => -5]]));
        self::assertSame(10.0, self::callPrivate('batchTimeout', [['timeout' => 10]]));
        self::assertSame(30.0, self::callPrivate('batchTimeout', [['timeout' => 999]]));
    }

    public function testBatchMaxBytesDefaultsAndOverride(): void
    {
        $default = 5 * 1024 * 1024;
        self::assertSame($default, self::callPrivate('batchMaxBytes', [[]]));
        self::assertSame($default, self::callPrivate('batchMaxBytes', [['limit_response_size' => 0]]));
        self::assertSame(1024, self::callPrivate('batchMaxBytes', [['limit_response_size' => 1024]]));
    }

    public function testBatchHeadersNormalisation(): void
    {
        $out = self::callPrivate('batchHeaders', [[
            'headers' => [
                'Accept'      => 'application/json',
                'X-Count'     => 5,
                'X-Ratio'     => 1.5,
                'X-Bad-Array' => ['nope'],
                7             => 'numeric-key-dropped',
            ],
        ]]);

        self::assertSame(
            [
                'Accept'  => 'application/json',
                'X-Count' => '5',
                'X-Ratio' => '1.5',
            ],
            $out
        );
    }

    public function testBatchHeadersRejectsNonArray(): void
    {
        self::assertSame([], self::callPrivate('batchHeaders', [['headers' => 'oops']]));
        self::assertSame([], self::callPrivate('batchHeaders', [[]]));
    }
}
