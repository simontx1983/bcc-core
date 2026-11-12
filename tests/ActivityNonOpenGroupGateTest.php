<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\NonOpenGroups {

    use BCC\Core\Repositories\PeepSoActivityRepository;
    use BCC\Core\Tests\Stubs\RenderingWpdb;
    use PHPUnit\Framework\Attributes\CoversMethod;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/Stubs/rendering-wpdb.php';
    require_once __DIR__ . '/Stubs/nonopen-groups-seed.php';

    /**
     * F-23: once a group turns closed or secret, ALL its posts leave every
     * read behind the global-feed gate (the global and hot feeds), for
     * everyone, retroactively, even posts marked 'public_all' while the
     * group was open. The group's own feed is unchanged.
     *
     * Asserts the STATEMENT (the $wpdb double renders SQL; it cannot
     * filter rows): which predicate reaches SQL on which path.
     */
    #[CoversMethod(PeepSoActivityRepository::class, 'getActivities')]
    final class ActivityNonOpenGroupGateTest extends TestCase
    {
        private const NON_OPEN_7_9 = "NOT EXISTS (SELECT 1 FROM wp_postmeta no_pm WHERE no_pm.post_id = p.ID"
            . " AND no_pm.meta_key = 'peepso_group_id' AND no_pm.meta_value IN ('7','9'))";

        private const ANY_GROUP = "NOT EXISTS (SELECT 1 FROM wp_postmeta no_pm WHERE no_pm.post_id = p.ID"
            . " AND no_pm.meta_key = 'peepso_group_id')";

        /** @var mixed */
        private $priorWpdb;

        protected function setUp(): void
        {
            $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
            \BccTestPersistentCache::reset();
        }

        protected function tearDown(): void
        {
            \BccTestPersistentCache::reset();
            if ($this->priorWpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $this->priorWpdb;
            }
        }

        private static function sql(): string
        {
            return (string) $GLOBALS['wpdb']->lastSql;
        }

        public function testGlobalReadDropsPostsInNonOpenGroups(): void
        {
            $GLOBALS['wpdb'] = new RenderingWpdb();
            \bcc_test_seed_nonopen_groups([7, 9]);

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertStringContainsString(self::NON_OPEN_7_9, self::sql());
            // Alongside, not instead of, the public_all gate.
            self::assertStringContainsString("vis_pm.meta_value = 'public_all'", self::sql());
        }

        public function testNoNonOpenGroupsAddsNothing(): void
        {
            $GLOBALS['wpdb'] = new RenderingWpdb();
            \bcc_test_seed_nonopen_groups([]);

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertStringNotContainsString('no_pm', self::sql());
        }

        public function testAFailedLookupDropsEveryGroupTaggedPost(): void
        {
            // Cache cold and the strict lookup's query fails → it returns
            // null ("cannot answer") → fail closed.
            $GLOBALS['wpdb'] = new FailingLookupWpdb();

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertStringContainsString(self::ANY_GROUP, self::sql());
            self::assertStringNotContainsString('no_pm.meta_value IN', self::sql());
        }

        public function testTheGroupsOwnFeedIsUnchanged(): void
        {
            $GLOBALS['wpdb'] = new RenderingWpdb();
            \bcc_test_seed_nonopen_groups([7, 9]);

            PeepSoActivityRepository::getActivities(null, null, null, null, 10, null, null, 7);

            self::assertStringNotContainsString('no_pm', self::sql());
        }

        public function testARelaxedViewerAccessReadKeepsItsOwnRule(): void
        {
            // The profile Blog tab: its deny list (bcc-trust) decides group
            // visibility there, not the global gate.
            $GLOBALS['wpdb'] = new RenderingWpdb();
            \bcc_test_seed_nonopen_groups([7, 9]);

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10,
                restrictedGroupIds: [7],
                groupGateMode: PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS,
                viewerMemberGroupIds: [],
                viewerId: 5
            );

            self::assertStringNotContainsString('no_pm', self::sql());
        }
    }

    /**
     * RenderingWpdb plus a failing query(): the strict non-open lookup
     * runs prepare() → query() and must see the failure.
     */
    final class FailingLookupWpdb
    {
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $prefix   = 'wp_';
        public string $last_error = '';
        private RenderingWpdb $inner;

        public function __construct()
        {
            $this->inner = new RenderingWpdb();
        }

        public function query(string $sql): bool
        {
            $this->last_error = 'simulated failure';
            return false;
        }

        /** @param mixed ...$args */
        public function __call(string $name, array $args): mixed
        {
            if ($name === 'get_results') {
                $this->last_error = '';
            }
            return $this->inner->{$name}(...$args);
        }

        public function __get(string $name): mixed
        {
            return $this->inner->{$name};
        }
    }
}
