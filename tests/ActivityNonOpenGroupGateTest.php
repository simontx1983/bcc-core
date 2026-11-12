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
     * F-23 (option B) + Gate 12: once a group turns closed or secret, the
     * posts made while it was OPEN (or with no record) leave every read
     * behind the global-feed gate, for everyone. A post made public_all
     * while the group was ALREADY non-open keeps syndicating ("public_all
     * wins"). The group's own feed is unchanged. The rule itself is
     * GroupSyndicationGate; this pins that the repository applies it.
     *
     * Asserts the STATEMENT (the $wpdb double renders SQL; it cannot
     * filter rows): which predicate reaches SQL on which path.
     */
    #[CoversMethod(PeepSoActivityRepository::class, 'getActivities')]
    final class ActivityNonOpenGroupGateTest extends TestCase
    {
        /** Gate 12 escape: a post recorded as made while its group was already non-open. */
        private const MADE_WHILE_NON_OPEN = "EXISTS (SELECT 1 FROM wp_postmeta gsg_at WHERE gsg_at.post_id = p.ID"
            . " AND gsg_at.meta_key = '_bcc_post_group_privacy_at_post' AND gsg_at.meta_value IN ('1','2'))";

        private const NON_OPEN_7_9 = "(NOT EXISTS (SELECT 1 FROM wp_postmeta gsg_g WHERE gsg_g.post_id = p.ID"
            . " AND gsg_g.meta_key = 'peepso_group_id' AND gsg_g.meta_value IN ('7','9')) OR " . self::MADE_WHILE_NON_OPEN . ")";

        private const ANY_GROUP = "(NOT EXISTS (SELECT 1 FROM wp_postmeta gsg_g WHERE gsg_g.post_id = p.ID"
            . " AND gsg_g.meta_key = 'peepso_group_id') OR " . self::MADE_WHILE_NON_OPEN . ")";

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

            self::assertStringNotContainsString('gsg_', self::sql());
        }

        public function testAFailedLookupDropsEveryGroupTaggedPost(): void
        {
            // Cache cold and the strict lookup's query fails → it returns
            // null ("cannot answer") → fail closed.
            $GLOBALS['wpdb'] = new FailingLookupWpdb();

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertStringContainsString(self::ANY_GROUP, self::sql());
            self::assertStringNotContainsString('gsg_g.meta_value IN', self::sql());
        }

        public function testTheGroupsOwnFeedIsUnchanged(): void
        {
            $GLOBALS['wpdb'] = new RenderingWpdb();
            \bcc_test_seed_nonopen_groups([7, 9]);

            PeepSoActivityRepository::getActivities(null, null, null, null, 10, null, null, 7);

            self::assertStringNotContainsString('gsg_', self::sql());
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

            self::assertStringNotContainsString('gsg_', self::sql());
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
