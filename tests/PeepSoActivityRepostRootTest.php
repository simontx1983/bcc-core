<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests;

use BCC\Core\Repositories\PeepSoActivityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * F-346 — `resolveRepostRoot()`.
 *
 * ⚠ Only three behaviours matter here, and the second is the one that makes
 * the feature correct rather than merely working:
 *
 *   1. a non-repost is its own root;
 *   2. a repost resolves to the stored root in ONE hop, because PeepSo wrote
 *      the ROOT and not the immediate parent (activity.php:153-155) — so
 *      reposting a repost of X yields X, and the once-per-post rule cannot be
 *      bypassed by submitting a different act_id for the same content;
 *   3. an unresolvable activity returns 0, which is "I could not tell" and
 *      never "it is its own root".
 *
 * The SQL itself is covered by the existing getById() suites; this pins the
 * derivation on top of it.
 */
#[CoversClass(PeepSoActivityRepository::class)]
final class PeepSoActivityRepostRootTest extends TestCase
{
    /** @var mixed */
    private $priorWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
        parent::tearDown();
    }

    /** @param object|null $row what get_row() should answer with */
    private function installWpdb($row): void
    {
        $GLOBALS['wpdb'] = new class ($row) {
            public string $prefix = 'wp_';
            public string $posts  = 'wp_posts';
            /** @var object|null */
            private $row;
            /** @var list<string> */
            public array $queries = [];

            /** @param object|null $row */
            public function __construct($row)
            {
                $this->row = $row;
            }

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                unset($args);

                return $sql;
            }

            /** @param string $sql @return object|null */
            public function get_row($sql)
            {
                $this->queries[] = (string) $sql;

                return $this->row;
            }
        };
    }

    public function testANonRepostIsItsOwnRoot(): void
    {
        $this->installWpdb((object) ['act_id' => 500, 'act_repost_id' => 0]);

        self::assertSame(500, PeepSoActivityRepository::resolveRepostRoot(500));
    }

    /**
     * ⭐ ONE HOP. The row for a repost OF A REPOST already holds the original,
     * so this returns 9000 and not the intermediate 700.
     */
    public function testARepostResolvesToTheStoredRootInOneHop(): void
    {
        $this->installWpdb((object) ['act_id' => 700, 'act_repost_id' => 9000]);

        self::assertSame(9000, PeepSoActivityRepository::resolveRepostRoot(700));
    }

    /** Numeric strings are what $wpdb actually hands back. */
    public function testNumericStringsFromWpdbAreCoerced(): void
    {
        $this->installWpdb((object) ['act_id' => '700', 'act_repost_id' => '9000']);

        self::assertSame(9000, PeepSoActivityRepository::resolveRepostRoot(700));
    }

    /**
     * ⚠ FAILS CLOSED. `getById()` INNER JOINs wp_posts, so a deleted post
     * yields no row — and 0 must mean "could not tell", never "it is its own
     * root". A caller that treated 0 as the root would make a vanished post a
     * writable repost target.
     */
    public function testAMissingActivityReturnsZeroAndNotTheInputId(): void
    {
        $this->installWpdb(null);

        self::assertSame(0, PeepSoActivityRepository::resolveRepostRoot(700));
    }

    public function testANonPositiveIdIsRefusedWithoutAQuery(): void
    {
        $this->installWpdb((object) ['act_id' => 1, 'act_repost_id' => 0]);

        self::assertSame(0, PeepSoActivityRepository::resolveRepostRoot(0));
        self::assertSame(0, PeepSoActivityRepository::resolveRepostRoot(-5));
        self::assertSame([], $GLOBALS['wpdb']->queries, 'no query should have been issued');
    }

    /**
     * ⚠ The column has to be SELECTED or the derivation silently reads a
     * missing property as 0 and every repost looks like its own root. Pinned
     * against the rendered SQL, because that failure is invisible otherwise.
     */
    public function testTheRepostColumnIsActuallySelected(): void
    {
        $this->installWpdb((object) ['act_id' => 700, 'act_repost_id' => 9000]);

        PeepSoActivityRepository::resolveRepostRoot(700);

        self::assertCount(1, $GLOBALS['wpdb']->queries);
        self::assertStringContainsString('a.act_repost_id', $GLOBALS['wpdb']->queries[0]);
    }
}
