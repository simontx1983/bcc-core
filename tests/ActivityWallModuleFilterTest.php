<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\WallModuleFilter {

    use BCC\Core\Feed\ActivityFeedService;
    use BCC\Core\Feed\FeedItemNormalizer;
    use BCC\Core\Tests\Stubs\RenderingWpdb;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    require_once __DIR__ . '/Stubs/rendering-wpdb.php';

    /**
     * The author wall's caller-supplied module ALLOWLIST.
     *
     * The channel exists so a caller can drop a whole KIND from a wall
     * (bcc-trust hides the Blogs and Reviews sections this way) WITHOUT
     * filtering a fetched page. That distinction is the whole point and is
     * what these tests pin:
     *
     *   - filtering CANDIDATES in the WHERE keeps each page full, so
     *     `has_more` and `next_cursor` keep describing the page the caller
     *     actually got;
     *   - filtering a fetched page would return short pages and could end
     *     a cursor early, dropping posts the viewer was entitled to see.
     *
     * The `$wpdb` double renders bound parameters INTO the statement, so
     * these read the values MySQL would receive rather than trusting a
     * placeholder count. They assert the STATEMENT, not returned rows —
     * the double echoes back whatever it is handed and so cannot prove row
     * filtering. What they can prove is the part most likely to be wrong
     * and least likely to be noticed: which module ids reach the query.
     */
    #[CoversClass(ActivityFeedService::class)]
    final class ActivityWallModuleFilterTest extends TestCase
    {
        /** PeepSo module id for a BCC blog excerpt (FeedItemNormalizer::MODULE_TO_KIND). */
        private const MODULE_BLOG = 204;

        /** PeepSo module id for a BCC review. */
        private const MODULE_REVIEW = 202;

        /** @var mixed */
        private $priorWpdb;

        protected function setUp(): void
        {
            require_once dirname(__DIR__) . '/tests/Stubs/nonopen-groups-seed.php';
            // F-23 gate: default to "no group is non-open" so the rendered
            // SQL is the shape these tests pin.
            \bcc_test_seed_nonopen_groups([]);
            $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        }

        protected function tearDown(): void
        {
            if ($this->priorWpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $this->priorWpdb;
            }
        }

        private static function service(): ActivityFeedService
        {
            // hydrateRows() returns early on an empty row set, so no
            // constructor dependency is touched on this path.
            /** @var ActivityFeedService */
            return (new ReflectionClass(ActivityFeedService::class))->newInstanceWithoutConstructor();
        }

        /**
         * @param list<int>|null $moduleIds
         * @return array{sql: string, queries: int, payload: array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}}
         */
        private function wall(?array $moduleIds): array
        {
            $wpdb            = new RenderingWpdb();
            $GLOBALS['wpdb'] = $wpdb;

            $payload = self::service()->getActivityForAuthor(
                77,
                5,
                null,
                20,
                null,
                null,
                null,
                null,
                false,
                $moduleIds
            );

            return [
                'sql'     => trim($wpdb->lastSql),
                'queries' => $wpdb->queryCount,
                'payload' => $payload,
            ];
        }

        /** The rendered `act_module_id IN (...)` clause, or '' when absent. */
        private static function moduleClause(string $sql): string
        {
            return preg_match('/act_module_id IN \([^)]*\)/', $sql, $m) === 1 ? $m[0] : '';
        }

        /**
         * The DEFAULT must stay byte-identical for every pre-existing
         * caller: null means "core applies its own public allowlist", and
         * a wall still shows every kind — blogs and reviews included.
         *
         * This is the anti-vacuity control for the whole suite. A filter
         * that dropped module 204 unconditionally would satisfy every
         * "204 is absent" assertion below while silently breaking every
         * wall on the site; this is the test that fails if it does.
         */
        public function testTheDefaultStillAdmitsEveryPublicModule(): void
        {
            $out = $this->wall(null);

            self::assertSame(
                'act_module_id IN (1,4,200,201,202,203,204,205)',
                self::moduleClause($out['sql']),
                'null must leave core\'s own publicFeedModuleIds() gate in place, bound as ints'
            );
            self::assertStringContainsString(
                (string) self::MODULE_BLOG,
                self::moduleClause($out['sql']),
                'a wall shows blog posts unless its caller says otherwise'
            );
        }

        /**
         * A non-empty allowlist replaces the default gate, and reaches the
         * WHERE rather than being applied afterwards.
         */
        public function testAnAllowlistReachesTheWhereClause(): void
        {
            $out = $this->wall([1, self::MODULE_REVIEW]);

            self::assertSame(
                "act_module_id IN ('1','202')",
                self::moduleClause($out['sql']),
                'the repository binds an explicit list as strings (%s), its own default as ints (%d)'
            );
            self::assertSame(1, $out['queries'], 'one candidate query, not a fetch-then-filter');
        }

        /**
         * The case bcc-trust actually uses: the Blogs section is switched
         * off, so the blog module never enters the candidate set.
         *
         * ⚠ Asserted on the MODULE CLAUSE, not on the whole statement:
         * `204` as a bare substring could match a limit, an id or an
         * offset elsewhere in the SQL and make this pass for the wrong
         * reason.
         */
        public function testExcludingTheBlogModuleKeepsItOutOfTheCandidateQuery(): void
        {
            $allowed = array_values(array_diff(
                FeedItemNormalizer::publicFeedModuleIds(),
                [self::MODULE_BLOG]
            ));

            $clause = self::moduleClause($this->wall($allowed)['sql']);

            self::assertStringNotContainsString("'" . self::MODULE_BLOG . "'", $clause);
            self::assertStringContainsString("'" . self::MODULE_REVIEW . "'", $clause, 'only blogs were dropped');
            self::assertStringContainsString("'1'", $clause, 'ordinary statuses still show');
        }

        /** The same channel, used for the Reviews section. */
        public function testExcludingTheReviewModuleKeepsItOutOfTheCandidateQuery(): void
        {
            $allowed = array_values(array_diff(
                FeedItemNormalizer::publicFeedModuleIds(),
                [self::MODULE_REVIEW]
            ));

            $clause = self::moduleClause($this->wall($allowed)['sql']);

            self::assertStringNotContainsString("'" . self::MODULE_REVIEW . "'", $clause);
            self::assertStringContainsString("'" . self::MODULE_BLOG . "'", $clause, 'only reviews were dropped');
        }

        /**
         * An empty allowlist is a real answer — "every kind this wall could
         * show is switched off" — not a missing filter. It must issue NO
         * query at all, and must not render `IN ()`, which is a syntax
         * error rather than a match-nothing.
         */
        public function testAnEmptyAllowlistIssuesNoQueryAtAll(): void
        {
            $out = $this->wall([]);

            self::assertSame(0, $out['queries'], 'no candidate query for an empty allowlist');
            self::assertSame([], $out['payload']['items']);
            self::assertFalse($out['payload']['pagination']['has_more']);
            self::assertNull($out['payload']['pagination']['next_cursor']);
        }

        /**
         * Narrowing the modules must not disturb the author scope — a wall
         * is still one author's slice.
         */
        public function testNarrowingModulesLeavesTheAuthorScopeIntact(): void
        {
            $sql = $this->wall([1])['sql'];

            self::assertStringContainsString('p.post_author IN (77)', $sql);
        }
    }
}
