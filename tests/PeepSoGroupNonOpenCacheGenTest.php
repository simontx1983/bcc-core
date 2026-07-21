<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoGroupRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression pins for the 2026-07-21 admin-audit P1 (bcc-core site):
 * the non-open-groups generation counter must tolerate NUMERIC STRINGS
 * from the object cache.
 *
 * Persistent backends (Redis/Predis, memcached) serialize with
 * maybe_serialize(), so an integer generation written by one PHP
 * process reads back as a numeric string in every other process. The
 * old strict `is_int(...)` guards (a) reset the generation to 0 on
 * every cross-process nonOpenCacheKey() read and (b) re-initialized
 * the counter to 0 inside bustNonOpenGroupIdsCache() before the
 * increment — so privacy-change busts never invalidated
 * getNonOpenGroupIds() caches.
 *
 * The wp_cache_* shims below simulate the cross-process view (ints
 * stringify on read; Redis INCR semantics). Each test runs in its own
 * subprocess so the shims never leak into the main process. Same
 * simulation lives in bcc-trust's tests/Stubs/object-cache-stubs.php
 * for the sibling fixes (HiddenActivityRepository,
 * BlogChainTagRepository).
 */
#[CoversMethod(PeepSoGroupRepository::class, 'nonOpenCacheKey')]
#[CoversMethod(PeepSoGroupRepository::class, 'bustNonOpenGroupIdsCache')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoGroupNonOpenCacheGenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/object-cache-stubs.php';
        \BccTestPersistentCache::reset();
    }

    private static function genKeyName(): string
    {
        $ref = new \ReflectionClassConstant(PeepSoGroupRepository::class, 'NONOPEN_CACHE_KEY_GEN');
        $val = $ref->getValue();
        self::assertIsString($val);
        return $val;
    }

    private static function cacheGroupName(): string
    {
        $ref = new \ReflectionClassConstant(PeepSoGroupRepository::class, 'NONOPEN_CACHE_GROUP');
        $val = $ref->getValue();
        self::assertIsString($val);
        return $val;
    }

    private static function nonOpenCacheKey(int $limit): string
    {
        $ref = new ReflectionMethod(PeepSoGroupRepository::class, 'nonOpenCacheKey');
        $ref->setAccessible(true);
        $out = $ref->invoke(null, $limit);
        self::assertIsString($out);
        return $out;
    }

    public function testCacheKeySurvivesStringRoundTrip(): void
    {
        wp_cache_set(self::genKeyName(), 6, self::cacheGroupName());
        // Cross-process view: the stub returns '6' (string).
        self::assertSame('6', wp_cache_get(self::genKeyName(), self::cacheGroupName()));
        self::assertStringEndsWith(':6', self::nonOpenCacheKey(50));
    }

    public function testCacheKeyReadDoesNotResetGeneration(): void
    {
        wp_cache_set(self::genKeyName(), 4, self::cacheGroupName());
        self::assertStringEndsWith(':4', self::nonOpenCacheKey(50));
        // The old is_int guard reset the counter to 0 on first read.
        self::assertStringEndsWith(':4', self::nonOpenCacheKey(50));
        self::assertSame(4, (int) \BccTestPersistentCache::raw(self::genKeyName(), self::cacheGroupName()));
    }

    public function testBustIncrementsInsteadOfReinitializing(): void
    {
        wp_cache_set(self::genKeyName(), 9, self::cacheGroupName());
        // Old bug: is_int(wp_cache_get(...)) failed on '9', the bust
        // re-initialized to 0, then incremented — landing on 1 and
        // erasing all prior generations. Must land on 10.
        PeepSoGroupRepository::bustNonOpenGroupIdsCache();
        self::assertStringEndsWith(':10', self::nonOpenCacheKey(50));
    }

    public function testBustOnColdCacheInitializesThenIncrements(): void
    {
        PeepSoGroupRepository::bustNonOpenGroupIdsCache();
        self::assertStringEndsWith(':1', self::nonOpenCacheKey(50));
    }
}
