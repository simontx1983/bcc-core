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
    }
}
