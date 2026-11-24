<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\RepostRootId;

use BCC\Core\Feed\FeedItemNormalizer;
use BCC\Core\Repositories\PeepSoActivityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-346 — the repost ROOT act id travels on the normalized feed item.
 *
 * ── WHY THIS IS ON THE ITEM AND NOT RESOLVED PER ITEM ───────────────
 *
 * `PeepSoActivityRepository::COLUMNS` already ends in `a.act_repost_id`, so
 * every activity row the feed reads is already carrying the root. Resolving
 * it through `resolveRepostRoot()` instead would cost one `getById()` row
 * read PER ITEM — an N+1 on every feed page — to learn what the row in hand
 * already says.
 *
 * ⚠⚠ THAT MAKES THIS A COUPLING, NOT A FREE LUNCH, and the coupling fails
 * SILENTLY in the dangerous direction. If `act_repost_id` ever leaves
 * `COLUMNS`, `$row->act_repost_id` simply stops being set and every item is
 * stamped 0 — reposts quietly stop being reposts, with nothing throwing and
 * no feed test failing, because 0 is also the legitimate answer for an
 * ordinary post. {@see testTheColumnThisDependsOnIsStillSelected} is the
 * only thing standing between that change and a silent feature removal.
 *
 * ⛔ Nothing here is a readability verdict. Whether a viewer may see the root
 * is decided per viewer in `FeedRankingService::pageFilters()`, because this
 * stage also runs for the viewer-independent shared hot-feed cache.
 */
#[CoversClass(FeedItemNormalizer::class)]
final class FeedItemRepostRootIdTest extends TestCase
{
    private const ACT_ID = 5150;
    private const ROOT_ID = 4242;

    /** @param array<string, mixed> $extra */
    private static function row(array $extra = []): object
    {
        return (object) ([
            'act_id'          => self::ACT_ID,
            'act_module_id'   => '1',
            'act_external_id' => 991,
            'act_time'        => '2026-11-24 12:00:00',
            'act_access'      => '0',
        ] + $extra);
    }

    /** @param array<string, mixed> $extra */
    private static function normalize(array $extra = []): array
    {
        return FeedItemNormalizer::normalize(self::row($extra), ['id' => 7, 'handle' => 'someone']);
    }

    public function testARepostCarriesItsRootsActId(): void
    {
        self::assertSame(
            self::ROOT_ID,
            self::normalize(['act_repost_id' => self::ROOT_ID])['repost_root_id']
        );
    }

    /**
     * ⚠⚠ THE LOCKSTEP TRAP, PINNED. `resolveRepostRoot()` answers the act's
     * OWN id when the act is not a repost; this field answers 0. The two
     * deliberately disagree because they answer different questions — "what
     * is the root of this chain" versus "is there a root to show at all".
     *
     * Without this assertion, somebody "aligning" the two would make every
     * ordinary post look like a repost of itself, and the feed would render a
     * nested copy of each post inside itself.
     */
    public function testAnOrdinaryPostCarriesZeroAndNotItsOwnId(): void
    {
        $item = self::normalize(['act_repost_id' => 0]);

        self::assertSame(0, $item['repost_root_id']);
        self::assertNotSame(
            self::ACT_ID,
            $item['repost_root_id'],
            'an ordinary post must NOT report itself as its own root'
        );
    }

    /**
     * Fail-safe for the coupling above: an absent column answers 0 rather
     * than warning or throwing. That keeps a COLUMNS change from taking the
     * whole feed down — the cost is that it goes quiet instead, which is
     * exactly why the COLUMNS test below exists.
     */
    public function testAnAbsentColumnIsZeroRatherThanAWarning(): void
    {
        self::assertSame(0, self::normalize()['repost_root_id']);
    }

    /**
     * MySQL hands back strings, so the cast is load-bearing rather than
     * decorative: an unconverted '4242' would fail a `=== 0` check in a
     * consumer while also not being an int id.
     *
     * @param mixed $stored
     */
    #[DataProvider('driverShapes')]
    public function testWhateverTheDriverHandsBackBecomesAnInt($stored, int $expected): void
    {
        $item = self::normalize(['act_repost_id' => $stored]);

        self::assertIsInt($item['repost_root_id']);
        self::assertSame($expected, $item['repost_root_id']);
    }

    /** @return iterable<string, array{0: mixed, 1: int}> */
    public static function driverShapes(): iterable
    {
        yield 'a string id, as mysqli returns it' => ['4242', self::ROOT_ID];
        yield 'a string zero'                     => ['0', 0];
        yield 'null'                              => [null, 0];
        yield 'an empty string'                   => ['', 0];
    }

    /**
     * ⛔ THE SILENT-REMOVAL GUARD. This is the one test that is not about the
     * normalizer at all: it asserts the column the normalizer depends on is
     * still selected. See the class docblock — if this coupling breaks,
     * nothing else in either plugin goes red.
     */
    public function testTheColumnThisDependsOnIsStillSelected(): void
    {
        // ⚠ COLUMNS is PRIVATE, read by reflection rather than by scanning
        // the file's text: reflection reads the CONSTANT'S REAL VALUE, so a
        // renamed, re-cased or conditionally-built column list cannot pass
        // this while the feed is actually broken. A grep over the source
        // would also match a comment that merely NAMES the column.
        $columns = (string) (new \ReflectionClass(PeepSoActivityRepository::class))
            ->getConstant('COLUMNS');

        self::assertNotSame('', $columns, 'reflection must really have read the constant');

        self::assertStringContainsString(
            'act_repost_id',
            $columns,
            'FeedItemNormalizer reads $row->act_repost_id; dropping it from COLUMNS '
            . 'would stamp every item 0 and silently un-repost the whole feed'
        );
    }

    /**
     * The stamp is unconditional: it does not depend on permissions, a
     * viewer, or any hydrator having run. A repost is a fact about the row.
     */
    public function testTheStampDoesNotDependOnPermissionsHavingBeenResolved(): void
    {
        $bare = FeedItemNormalizer::normalize(
            self::row(['act_repost_id' => self::ROOT_ID]),
            ['id' => 7, 'handle' => 'someone']
        );

        self::assertSame(self::ROOT_ID, $bare['repost_root_id']);
        self::assertArrayHasKey('permissions', $bare, 'defaults still applied');
    }
}
