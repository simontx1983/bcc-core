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
            require_once dirname(__DIR__) . '/tests/Stubs/nonopen-groups-seed.php';
            // F-23 gate: default to "no group is non-open" so the rendered
            // SQL is the pre-F-23 shape these tests pin.
            \bcc_test_seed_nonopen_groups([]);
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

        /**
         * The same predicate with the group-tagged branch handed to the
         * caller ($deferGroupsToCaller = true).
         */
        private static function isVisibleGloballyDeferred(int $postId): bool
        {
            $ref = new ReflectionMethod(ActivityFeedService::class, 'isVisibleGlobally');
            $ref->setAccessible(true);
            $out = $ref->invoke(null, $postId, true);
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

        // ── F-23 (option B) + Gate 12 ───────────────────────────────────
        // A post made while the group was OPEN (or with no record) drops
        // once the group is closed or secret; one made public_all while the
        // group was ALREADY non-open keeps syndicating.

        public function testPublicAllMadeWhileNonOpenKeepsSyndicating(): void
        {
            \bcc_test_seed_nonopen_groups([7]);
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'                  => '7',
                '_bcc_post_visibility'             => 'public_all',
                '_bcc_post_group_privacy_at_post'  => '1',
            ];
            self::assertTrue(self::isVisibleGlobally(123));
        }

        public function testPublicAllMadeWhileOpenDropsOnceTheGroupCloses(): void
        {
            \bcc_test_seed_nonopen_groups([7]);
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'                  => '7',
                '_bcc_post_visibility'             => 'public_all',
                '_bcc_post_group_privacy_at_post'  => '0',
            ];
            self::assertFalse(self::isVisibleGlobally(123));
        }

        public function testMadeWhileNonOpenSurvivesAFailedLookup(): void
        {
            // Deliberate non-open syndication is allowed whatever the
            // group's state is now, so it needs no lookup at all.
            \BccTestPersistentCache::reset();
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'                  => '7',
                '_bcc_post_visibility'             => 'public_all',
                '_bcc_post_group_privacy_at_post'  => '2',
            ];
            self::assertTrue(self::isVisibleGlobally(123));
        }

        // No record (posts written before the record existed): treated
        // as made while open, so it drops.
        public function testPublicAllPostInANowClosedOrSecretGroupIsHidden(): void
        {
            \bcc_test_seed_nonopen_groups([7]);
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => '7',
                '_bcc_post_visibility' => 'public_all',
            ];
            self::assertFalse(self::isVisibleGlobally(123));
        }

        public function testPublicAllPostInAnOpenGroupStaysVisible(): void
        {
            \bcc_test_seed_nonopen_groups([9]);
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => '7',
                '_bcc_post_visibility' => 'public_all',
            ];
            self::assertTrue(self::isVisibleGlobally(123));
        }

        public function testAPostInTwoGroupsIsHiddenIfEitherIsNonOpen(): void
        {
            \bcc_test_seed_nonopen_groups([12]);
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => ['7', '12'],
                '_bcc_post_visibility' => 'public_all',
            ];
            self::assertFalse(self::isVisibleGlobally(123));
        }

        public function testAFailedNonOpenLookupHidesGroupPostsButNotOthers(): void
        {
            // Cold cache + a failing query: the strict lookup returns null.
            \BccTestPersistentCache::reset();
            $prior = $GLOBALS['wpdb'] ?? null;
            $GLOBALS['wpdb'] = new class {
                public string $posts = 'wp_posts';
                public string $postmeta = 'wp_postmeta';
                public string $last_error = '';
                /** @param mixed ...$a */
                public function prepare(string $q, ...$a): string { return $q; }
                public function query(string $q): bool { $this->last_error = 'simulated'; return false; }
            };
            try {
                $GLOBALS['__test_post_meta'][123] = [
                    'peepso_group_id'      => '7',
                    '_bcc_post_visibility' => 'public_all',
                ];
                self::assertFalse(self::isVisibleGlobally(123), 'group post fails closed');
                self::assertTrue(self::isVisibleGlobally(456), 'non-group post unaffected');
            } finally {
                if ($prior === null) {
                    unset($GLOBALS['wpdb']);
                } else {
                    $GLOBALS['wpdb'] = $prior;
                }
            }
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

        // ── $deferGroupVisibilityToCaller ─────────────────────
        //
        // The opt-in that lets a caller which CAN resolve group
        // membership (bcc-trust) decide a group-tagged post for itself,
        // instead of this layer refusing it because it cannot tell a
        // member from a stranger.
        //
        // ⚠ Every test here is paired with its default-OFF twin, because
        // the whole value of the parameter is that it changes nothing
        // until it is passed.

        /**
         * ⭐ THE PAIR THAT MATTERS. A `members_only` group post — the
         * DEFAULT for a group post — is refused by link as it always
         * was, and returned when the caller takes responsibility.
         */
        public function testMembersOnlyGroupPostIsStillRefusedByDefault(): void
        {
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => '7',
                '_bcc_post_visibility' => 'members_only',
            ];
            self::assertFalse(self::isVisibleGlobally(123));
        }

        public function testMembersOnlyGroupPostIsDeferredToTheCaller(): void
        {
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => '7',
                '_bcc_post_visibility' => 'members_only',
            ];
            self::assertTrue(self::isVisibleGloballyDeferred(123));
        }

        /** `public_group` behaves the same way: refused, then deferred. */
        public function testPublicGroupPostIsDeferredToTheCaller(): void
        {
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => '7',
                '_bcc_post_visibility' => 'public_group',
            ];
            self::assertFalse(self::isVisibleGlobally(123));
            self::assertTrue(self::isVisibleGloballyDeferred(123));
        }

        /**
         * ⚠⚠ THE FAIL-CLOSED CONTROL. Deferring must not become a
         * blanket "return true": a row with NO BACKING POST is still
         * refused, because that guard sits above the group branch and is
         * not a syndication question at all.
         */
        public function testDeferringStillRefusesARowWithNoBackingPost(): void
        {
            self::assertFalse(self::isVisibleGloballyDeferred(0));
            self::assertFalse(self::isVisibleGloballyDeferred(-5));
        }

        /**
         * A NON-group post is unaffected either way — it was always
         * visible, so the parameter cannot widen anything here. This is
         * what makes the change safe for every existing caller.
         */
        public function testANonGroupPostIsUnaffectedByDeferring(): void
        {
            $GLOBALS['__test_post_meta'][123] = [];
            self::assertTrue(self::isVisibleGlobally(123));
            self::assertTrue(self::isVisibleGloballyDeferred(123));
        }

        /**
         * Deferring bypasses the SYNDICATION question entirely, not just
         * the `public_all` check. A post made public_all while its group
         * was open, whose group has since become non-open, is refused by
         * the syndication rule — and deferred when the caller asks,
         * because "may a member open this" is a different question.
         */
        public function testDeferringBypassesTheSyndicationRuleToo(): void
        {
            \bcc_test_seed_nonopen_groups([7]);
            $GLOBALS['__test_post_meta'][123] = [
                'peepso_group_id'      => '7',
                '_bcc_post_visibility' => 'public_all',
            ];
            self::assertFalse(self::isVisibleGlobally(123));
            self::assertTrue(self::isVisibleGloballyDeferred(123));
        }
    }
}
