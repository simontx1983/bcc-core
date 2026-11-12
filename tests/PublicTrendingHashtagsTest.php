<?php

declare(strict_types=1);

namespace BCC\Core\Tests\Repositories;

use BCC\Core\Repositories\PeepSoHashtagRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-168: the public trending list ranks tags by how many posts ANYONE can
 * see carry them, not by PeepSo's `ht_count` (which counts closed/secret
 * group posts, members-only and private posts too).
 *
 * The SQL's meaning (a hidden-only tag is absent, a mixed tag's count drops
 * the hidden posts) is proven against real MySQL in bcc-trust's integration
 * suite, which loads this repository. This file pins what a fake $wpdb can:
 * the bound candidate read, the visibility predicates present in the
 * statement, the ranking/zero-drop/limit logic, the cache and fail-closed.
 */
#[CoversClass(PeepSoHashtagRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PublicTrendingHashtagsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/public-trending-stubs.php';
        $GLOBALS['wpdb'] = new \PublicTrendingFakeWpdb();
        $GLOBALS['__pt'] = ['transients' => [], 'ttl' => [], 'results' => [], 'sql' => []];
    }

    /** @param list<string> $names */
    private static function candidates(array $names): array
    {
        return array_map(static fn (string $n): object => (object) ['ht_name' => $n], $names);
    }

    /** @param list<int> $counts */
    private static function totals(array $counts): array
    {
        $row = [];
        foreach ($counts as $i => $c) {
            $row['c' . $i] = (string) $c; // MySQL hands SUMs back as strings
        }
        return [(object) $row];
    }

    /** @return list<array{0: string, 1: int}> */
    private static function pairs(array $rows): array
    {
        return array_map(static fn (object $r): array => [$r->ht_name, $r->ht_count], $rows);
    }

    public function testRanksByPublicCountAndDropsTagsWithNoPublicPost(): void
    {
        // PeepSo's order: hidden (most ht_count, every post hidden), mixed, small.
        $GLOBALS['__pt']['results'] = [self::candidates(['hidden', 'mixed', 'small']), self::totals([0, 2, 3])];

        $rows = PeepSoHashtagRepository::getTrendingPubliclyVisible(10);

        self::assertSame([['small', 3], ['mixed', 2]], self::pairs($rows));
    }

    public function testATieKeepsPeepSosOrder(): void
    {
        $GLOBALS['__pt']['results'] = [self::candidates(['first', 'second']), self::totals([4, 4])];

        self::assertSame([['first', 4], ['second', 4]], self::pairs(PeepSoHashtagRepository::getTrendingPubliclyVisible(10)));
    }

    public function testTheCandidateReadIsBoundedAndTheRecountIsOneGatedStatement(): void
    {
        $GLOBALS['__pt']['results'] = [self::candidates(['a_b', 'c']), self::totals([1, 1])];

        PeepSoHashtagRepository::getTrendingPubliclyVisible(5);

        self::assertCount(2, $GLOBALS['__pt']['sql'], 'one candidate read + ONE recount, not a query per tag');
        [$candidateSql, $recountSql] = $GLOBALS['__pt']['sql'];

        self::assertStringContainsString('LIMIT ' . PeepSoHashtagRepository::PUBLIC_CANDIDATES, $candidateSql);
        self::assertStringContainsString("WHERE ht_count > 0", $candidateSql);

        // PeepSo's match: wrapped in spaces, LIKE '% #tag %', wildcards escaped.
        self::assertStringContainsString("LIKE '% #a\\_b %'), 0) AS c0", $recountSql);
        self::assertStringContainsString("LIKE '% #c %'), 0) AS c1", $recountSql);
        self::assertStringContainsString("CONCAT(' ', REPLACE(REPLACE(REPLACE(REPLACE(p.post_content, CHAR(9), ' '), CHAR(13), ' '), CHAR(10), ' '), CHAR(194, 160 USING utf8mb4), ' '), ' ')", $recountSql);
        // The visibility gate, every part of it.
        foreach ([
            "p.post_type = 'peepso-post'",
            "p.post_status = 'publish'",
            "(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')",
            'COALESCE(pu.usr_profile_acc, 10)',
            'IF(a_pub.act_access IS NULL OR a_pub.act_access <= 0, 10, a_pub.act_access)',
            'NOT EXISTS (SELECT 1 FROM wp_peepso_activities a_hid',
            ') <> 10)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $recountSql);
        }
    }

    public function testTheRankingIsCachedOnceAndTheLimitOnlySlicesIt(): void
    {
        $GLOBALS['__pt']['results'] = [self::candidates(['x', 'y', 'z']), self::totals([3, 2, 1])];

        self::assertSame([['x', 3]], self::pairs(PeepSoHashtagRepository::getTrendingPubliclyVisible(1)));
        self::assertSame(PeepSoHashtagRepository::PUBLIC_CACHE_TTL, $GLOBALS['__pt']['ttl'][PeepSoHashtagRepository::PUBLIC_CACHE_KEY]);

        $queries = count($GLOBALS['__pt']['sql']);
        self::assertSame([['x', 3], ['y', 2], ['z', 1]], self::pairs(PeepSoHashtagRepository::getTrendingPubliclyVisible(20)));
        self::assertCount($queries, $GLOBALS['__pt']['sql'], 'served from the cache');
    }

    public function testTheLimitIsClamped(): void
    {
        $names = array_map(static fn (int $i): string => 't' . $i, range(0, 29));
        $GLOBALS['__pt']['results'] = [self::candidates($names), self::totals(array_fill(0, 30, 1))];

        self::assertCount(20, PeepSoHashtagRepository::getTrendingPubliclyVisible(500));
        self::assertCount(1, PeepSoHashtagRepository::getTrendingPubliclyVisible(0));
    }

    /** @return iterable<string, array{0: list<mixed>}> */
    public static function failures(): iterable
    {
        yield 'candidate read fails'               => [[false]];
        yield 'candidate read sets last_error'     => [[['error' => 'gone away']]];
        yield 'recount fails (stale last_result)'  => [[self::candidates(['a']), false]];
        yield 'recount sets last_error'            => [[self::candidates(['a']), ['error' => 'gone away']]];
    }

    /** @param list<mixed> $results */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAFailedReadFailsClosedAndIsNotCached(array $results): void
    {
        $GLOBALS['__pt']['results'] = $results;

        self::assertSame([], PeepSoHashtagRepository::getTrendingPubliclyVisible(10));
        self::assertArrayNotHasKey(PeepSoHashtagRepository::PUBLIC_CACHE_KEY, $GLOBALS['__pt']['transients']);
    }

    public function testACacheEntryOfAnotherShapeIsRecomputed(): void
    {
        $GLOBALS['__pt']['transients'][PeepSoHashtagRepository::PUBLIC_CACHE_KEY] = [['ht_name' => 'old', 'ht_count' => '9']];
        $GLOBALS['__pt']['results'] = [self::candidates(['new']), self::totals([1])];

        self::assertSame([['new', 1]], self::pairs(PeepSoHashtagRepository::getTrendingPubliclyVisible(10)));
    }
}
