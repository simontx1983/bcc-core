<?php

declare(strict_types=1);

// Namespaced stub: ActivityFeedService::isVisibleGlobally() calls the
// unqualified get_post_meta() from within BCC\Core\Feed, so PHP's
// namespace-fallback resolution picks this stub up before ever touching
// the global WordPress function — no WP core needed in this pure-unit
// bootstrap (see tests/bootstrap.php).
namespace BCC\Core\Feed {
    /** @var array<int, array<string, mixed>> $GLOBALS['__test_post_meta'] */
    function get_post_meta(int $postId, string $key, bool $single = false)
    {
        $meta = $GLOBALS['__test_post_meta'][$postId] ?? [];
        return $meta[$key] ?? '';
    }
}

namespace BCC\Core\Feed\Tests {

    use BCC\Core\Feed\ActivityFeedService;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;

    /**
     * Covers the two pieces of new pure logic in getActivityById(): the
     * invalid-id short-circuit (no DB touched) and the global-feed
     * visibility predicate it mirrors from
     * PeepSoActivityRepository::getActivities().
     */
    #[CoversClass(ActivityFeedService::class)]
    final class ActivityFeedServiceTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__test_post_meta'] = [];
        }

        private static function service(): ActivityFeedService
        {
            /** @var ActivityFeedService */
            return (new ReflectionClass(ActivityFeedService::class))->newInstanceWithoutConstructor();
        }

        private static function isVisibleGlobally(int $postId): bool
        {
            $ref = new ReflectionMethod(ActivityFeedService::class, 'isVisibleGlobally');
            $ref->setAccessible(true);
            $out = $ref->invoke(null, $postId);
            self::assertIsBool($out);
            return $out;
        }

        public function testInvalidActIdReturnsNullWithoutTouchingTheDb(): void
        {
            self::assertNull(self::service()->getActivityById(0, 1));
            self::assertNull(self::service()->getActivityById(-5, 1));
        }

        public function testExclusionListsAreOptional(): void
        {
            // Null exclusion lists must not themselves short-circuit —
            // only the $actId<=0 guard does, at this layer.
            self::assertNull(self::service()->getActivityById(0, 1, null, null));
        }

        public function testNonGroupPostIsVisible(): void
        {
            // No 'peepso_group_id' meta at all → non-group post → visible.
            self::assertTrue(self::isVisibleGlobally(123));
        }

        public function testGroupPostWithPublicAllVisibilityIsVisible(): void
        {
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => '7',
                '_bcc_post_visibility' => 'public_all',
            ];
            self::assertTrue(self::isVisibleGlobally(123));
        }

        public function testGroupPostWithoutPublicAllIsHidden(): void
        {
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => '7',
                '_bcc_post_visibility' => 'public_group',
            ];
            self::assertFalse(self::isVisibleGlobally(123));
        }

        public function testGroupPostWithMissingVisibilityMetaIsHidden(): void
        {
            // Absent _bcc_post_visibility ⇒ members_only ⇒ hidden, same
            // invariant as the SQL gate's NULL-passthrough.
            $GLOBALS['__test_post_meta'][123] = ['peepso_group_id' => '7'];
            self::assertFalse(self::isVisibleGlobally(123));
        }

        public function testZeroOrNegativePostIdIsNeverVisible(): void
        {
            self::assertFalse(self::isVisibleGlobally(0));
            self::assertFalse(self::isVisibleGlobally(-1));
        }

        // ── Batch-prime helpers (feed N+1 fix) ──────────────────────────

        private static function isStatusModule(string $module): bool
        {
            $ref = new ReflectionMethod(ActivityFeedService::class, 'isStatusModule');
            $ref->setAccessible(true);
            $out = $ref->invoke(null, $module);
            self::assertIsBool($out);
            return $out;
        }

        /**
         * @param array<int, object> $rows
         * @return list<int>
         */
        private static function collectPrimablePostIds(array $rows): array
        {
            $ref = new ReflectionMethod(ActivityFeedService::class, 'collectPrimablePostIds');
            $ref->setAccessible(true);
            $out = $ref->invoke(null, $rows);
            self::assertIsArray($out);
            return $out;
        }

        public function testIsStatusModuleTruthTable(): void
        {
            // PeepSo native writes '1' — but PeepSoActivity isn't loaded
            // in this pure-unit bootstrap, so only the string forms hold
            // here; the class_exists branch is exercised live.
            self::assertTrue(self::isStatusModule(''));
            self::assertTrue(self::isStatusModule('status'));
            self::assertFalse(self::isStatusModule('blog'));
            self::assertFalse(self::isStatusModule('review'));
        }

        public function testCollectPrimablePostIdsFiltersAndDedupes(): void
        {
            $rows = [
                (object) ['act_module_id' => 'status', 'act_external_id' => '10'],
                (object) ['act_module_id' => '',       'act_external_id' => 11],
                (object) ['act_module_id' => 'blog',   'act_external_id' => '12'],
                (object) ['act_module_id' => 'review', 'act_external_id' => '13'], // non-post module → excluded
                (object) ['act_module_id' => 'status', 'act_external_id' => '0'],  // no backing post → excluded
                (object) ['act_module_id' => 'blog',   'act_external_id' => -4],   // negative → excluded
                (object) ['act_module_id' => 'status', 'act_external_id' => '10'], // duplicate → deduped
            ];
            self::assertSame([10, 11, 12], self::collectPrimablePostIds($rows));
        }

        public function testCollectPrimablePostIdsEmptyInput(): void
        {
            self::assertSame([], self::collectPrimablePostIds([]));
        }
    }
}
