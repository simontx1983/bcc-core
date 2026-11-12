<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\PageScope {

    use BCC\Core\Feed\ActivityFeedService;
    use BCC\Core\Repositories\PeepSoActivityRepository;
    use BCC\Core\Tests\Stubs\RenderingWpdb;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    // The rendering `$wpdb` double was first written here and now lives in
    // tests/Stubs so the restricted-group exclusion suite shares it rather
    // than carrying a second copy (§11). Behaviour is unchanged.
    require_once __DIR__ . '/Stubs/rendering-wpdb.php';

    #[CoversClass(PeepSoActivityRepository::class)]
    #[CoversClass(ActivityFeedService::class)]
    final class ActivityPageScopeTest extends TestCase
    {
        private const MARKER_JOIN = "INNER JOIN wp_postmeta pg_pm ON pg_pm.post_id = p.ID AND pg_pm.meta_key = '_bcc_page_id' AND pg_pm.meta_value = '77'";

        /** @var mixed */
        private $priorWpdb;

        protected function setUp(): void
        {
            require_once dirname(__DIR__) . '/tests/Stubs/nonopen-groups-seed.php';
            // F-23 gate: default to "no group is non-open" so the rendered
            // SQL is the pre-F-23 shape these tests pin.
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

        private function installWpdb(): RenderingWpdb
        {
            $wpdb            = new RenderingWpdb();
            $GLOBALS['wpdb'] = $wpdb;

            return $wpdb;
        }

        public function testTheMarkerKeyIsTheOneBccTrustStamps(): void
        {
            self::assertSame('_bcc_page_id', PeepSoActivityRepository::PAGE_TARGET_META);
        }

        public function testNonPositivePageReturnsNoRowsWithoutQuerying(): void
        {
            $wpdb = $this->installWpdb();

            foreach ([0, -1] as $pageId) {
                self::assertSame([], PeepSoActivityRepository::getActivities(
                    null, null, null, null, 10, null, null, null, null, null, null, null, $pageId
                ));
            }

            self::assertSame(0, $wpdb->queryCount);
        }

        public function testPageScopeJoinsOnTheMarkerValue(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, 77
            );

            self::assertStringContainsString(self::MARKER_JOIN, $wpdb->lastSql);
        }

        public function testUnscopedQueryHasNoMarkerJoin(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertStringNotContainsString('_bcc_page_id', $wpdb->lastSql);
        }

        /**
         * JOIN params render before WHERE params. Bound in the wrong order, the
         * page id lands in an exclusion list and an excluded id lands in the
         * marker, so the feed serves the wrong page's rows without any error.
         */
        public function testJoinParamsStayAheadOfWhereParams(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null,
                null,
                '2026-11-05 10:00:00',
                900,
                21,
                [11, 12],
                [13],
                null,
                null,
                null,
                null,
                null,
                77
            );

            self::assertStringContainsString(self::MARKER_JOIN, $wpdb->lastSql);
            self::assertStringContainsString('p.post_author NOT IN (11,12)', $wpdb->lastSql);
            self::assertStringContainsString('a.act_id NOT IN (13)', $wpdb->lastSql);
            self::assertStringContainsString(
                "(p.post_date_gmt < '2026-11-05 10:00:00' OR (p.post_date_gmt = '2026-11-05 10:00:00' AND a.act_id < 900))",
                $wpdb->lastSql
            );
            self::assertStringEndsWith('LIMIT 21', trim($wpdb->lastSql));
        }

        public function testJoinParamOrderHoldsAlongsideTheGroupJoins(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, 42, null, ['public_all'], null, null, 77
            );

            self::assertStringContainsString("gm_pm.meta_value = '42'", $wpdb->lastSql);
            self::assertStringContainsString("vis_in_pm.meta_value IN ('public_all')", $wpdb->lastSql);
            self::assertStringContainsString(self::MARKER_JOIN, $wpdb->lastSql);
        }

        /**
         * A page-scoped read keeps the global gate: it runs with no group
         * scope, so a group-only post carrying a stray marker still stays out.
         */
        public function testPageScopeKeepsTheGlobalVisibilityGateAndModuleAllowlist(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, 77
            );

            self::assertStringContainsString(
                "(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')",
                $wpdb->lastSql
            );
            self::assertStringContainsString('a.act_module_id IN (', $wpdb->lastSql);
        }

        public function testServiceReturnsTheEmptyEnvelopeForANonPositivePage(): void
        {
            $wpdb = $this->installWpdb();

            $empty = ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
            self::assertSame($empty, self::service()->getActivityForPage(0, 5));
            self::assertSame($empty, self::service()->getActivityForPage(-3, 5));
            self::assertSame(0, $wpdb->queryCount);
        }

        public function testServiceForwardsPageExclusionsAndOverFetchesByOne(): void
        {
            $wpdb = $this->installWpdb();

            $out = self::service()->getActivityForPage(77, 0, null, 5, [11], [13]);

            self::assertStringContainsString(self::MARKER_JOIN, $wpdb->lastSql);
            self::assertStringContainsString('p.post_author NOT IN (11)', $wpdb->lastSql);
            self::assertStringContainsString('a.act_id NOT IN (13)', $wpdb->lastSql);
            self::assertStringEndsWith('LIMIT 6', trim($wpdb->lastSql));
            self::assertSame(['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]], $out);
        }

        public function testServiceClampsTheLimit(): void
        {
            $wpdb = $this->installWpdb();

            self::service()->getActivityForPage(77, 0, null, 5000);

            self::assertStringEndsWith('LIMIT 51', trim($wpdb->lastSql));
        }

        private static function service(): ActivityFeedService
        {
            /** @var ActivityFeedService */
            return (new ReflectionClass(ActivityFeedService::class))->newInstanceWithoutConstructor();
        }
    }
}
