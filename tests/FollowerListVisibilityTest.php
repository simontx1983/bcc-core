<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\FollowerVisibility {

    use BCC\Core\Repositories\PeepSoFollowerRepository;
    use BCC\Core\Tests\Stubs\RenderingWpdb;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/Stubs/rendering-wpdb.php';

    /**
     * §F-02 profile visibility for follower / following reads.
     *
     * ⚠⚠ THE INVARIANT THIS FILE EXISTS FOR: the id query and the COUNT
     * must apply the SAME rule. Filtering a follower list in PHP after a
     * LIMIT/OFFSET query under-fills the page AND leaves `total` counting
     * rows the viewer cannot see — and a count that disagrees with its
     * list announces "there are N hidden followers", which is a smaller
     * leak but still one. Both sides call one helper; these tests check
     * that the resulting predicates actually match, rather than trusting
     * that they were written from the same source.
     *
     * Statement-level assertions, like the sibling activity tests: the
     * `$wpdb` double echoes back what it is given, so it cannot prove row
     * filtering. What it CAN prove is which predicate reaches SQL and
     * WHICH VALUES ARE BOUND WHERE — the failure mode that does not throw.
     */
    #[CoversClass(PeepSoFollowerRepository::class)]
    final class FollowerListVisibilityTest extends TestCase
    {
        /** @var mixed */
        private $priorWpdb;

        protected function setUp(): void
        {
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

        // ── OFF by default ─────────────────────────────────────────────

        public function testFollowersIsUnchangedWhenTheSwitchIsOff(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, [7], false);

            self::assertStringNotContainsString('peepso_users', $wpdb->lastSql);
            self::assertStringNotContainsString('usr_profile_acc', $wpdb->lastSql);
            self::assertStringNotContainsString('IN (7)', $wpdb->lastSql, 'friend ids must not leak in');
        }

        public function testCountsAreUnchangedWhenTheSwitchIsOff(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getCounts(5, 42, [7], false);

            self::assertStringNotContainsString('peepso_users', $wpdb->lastSql);
            self::assertStringNotContainsString('usr_profile_acc', $wpdb->lastSql);
        }

        // ── Each read gates on the column holding the LISTED user ─────

        /**
         * `getFollowers($owner)` selects `uf_active_user_id` — the people
         * following the owner. So that is the column whose profile level
         * decides visibility, NOT the owner's.
         */
        public function testFollowersGatesOnTheFollowerNotTheOwner(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, [7, 9], true);

            self::assertStringContainsString('LEFT JOIN wp_peepso_users pu ON pu.usr_id = uf_active_user_id', $wpdb->lastSql);
            self::assertStringContainsString('CASE WHEN 42 > 0 AND uf_active_user_id = 42 THEN 1', $wpdb->lastSql);
            self::assertStringContainsString('<= 30 THEN IF(uf_active_user_id IN (7,9), 1, 0)', $wpdb->lastSql);
        }

        /** The mirror image: `getFollowing` lists the passive side. */
        public function testFollowingGatesOnThePassiveSide(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowing(5, 24, 0, 42, [7, 9], true);

            self::assertStringContainsString('LEFT JOIN wp_peepso_users pu ON pu.usr_id = uf_passive_user_id', $wpdb->lastSql);
            self::assertStringContainsString('<= 30 THEN IF(uf_passive_user_id IN (7,9), 1, 0)', $wpdb->lastSql);
        }

        /**
         * ⚠ THE BINDING TEST. `$userId` comes first, then the visibility
         * clause's three viewer slots and its friend ids, then LIMIT and
         * OFFSET — assembled by hand and matched positionally. If that
         * drifted, the owner id would land in a viewer slot or the page
         * bounds would bind as friend ids.
         */
        public function testOwnerViewerFriendsAndPageBoundsAllBindInOrder(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 48, 42, [7, 9], true);

            $sql = $wpdb->lastSql;
            self::assertStringContainsString('WHERE uf_passive_user_id = 5', $sql, 'the owner');
            self::assertStringContainsString('uf_active_user_id = 42 THEN 1', $sql, 'the viewer, own-row guard');
            self::assertStringContainsString('<= 20 THEN IF(42 > 0, 1, 0)', $sql, 'the viewer, members branch');
            self::assertStringContainsString('IN (7,9)', $sql, 'the friends');
            self::assertStringContainsString('LIMIT 24 OFFSET 48', $sql, 'the page bounds, still last');
        }

        // ── The invariant: list and count share one rule ──────────────

        /**
         * ⭐ Extracts the visibility predicate from the followers LIST and
         * from the followers COUNT and requires them to be identical.
         * Written as a comparison rather than two separate expectations
         * precisely because the bug being guarded against is the two
         * drifting apart.
         */
        public function testTheFollowersCountUsesExactlyTheListsPredicate(): void
        {
            $listWpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, [7, 9], true);
            $listPredicate = self::predicateOf($listWpdb->lastSql);

            $countWpdb = $this->installWpdb();
            PeepSoFollowerRepository::getCounts(5, 42, [7, 9], true);
            // getCounts issues two statements; lastSql is the followers one.
            $countPredicate = self::predicateOf($countWpdb->lastSql);

            self::assertNotSame('', $listPredicate, 'no predicate found in the list query — the extraction is broken');
            self::assertSame(
                $listPredicate,
                $countPredicate,
                'the followers COUNT must filter by the same rule as the followers LIST, '
                . 'or total disagrees with the rows and discloses how many are hidden'
            );
        }

        public function testTheTwoCountsGateOnOppositeColumns(): void
        {
            // Guards against the copy-paste error of gating both counts on
            // one column, which would make "following" count the wrong set.
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getCounts(5, 42, [7, 9], true);

            // lastSql is the SECOND statement (followers → active side).
            self::assertStringContainsString('pu.usr_id = uf_active_user_id', $wpdb->lastSql);
            self::assertStringContainsString('WHERE uf_passive_user_id = 5', $wpdb->lastSql);
        }

        // ── Degenerate inputs ─────────────────────────────────────────

        public function testAnonymousReaderGetsALiteralZeroFriendsBranch(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 0, null, true);

            self::assertStringContainsString('<= 30 THEN 0', $wpdb->lastSql, 'literal 0, never IN ()');
            self::assertStringNotContainsString('IN ()', $wpdb->lastSql);
            self::assertStringContainsString('<= 20 THEN IF(0 > 0, 1, 0)', $wpdb->lastSql);
        }

        /**
         * PRODUCT DECISION (user, 2026-11-11): BCC has no friends UI, so
         * the frontend hides "Friends only". Level 30 stays valid
         * server-side and, with an empty friends list, must behave
         * exactly like "Only me" — the listed user appears to themselves
         * and to admins, and to nobody else.
         *
         * Named explicitly so the decision cannot be quietly relaxed, and
         * asserted for a degraded read (`null`) as well as a genuinely
         * empty one (`[]`), because those arrive from different upstream
         * failure paths and must behave identically.
         */
        public function testLevel30WithNoFriendsAdmitsNobodyButTheListedUser(): void
        {
            foreach ([null, []] as $friends) {
                $wpdb = $this->installWpdb();
                PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, $friends, true);

                self::assertStringContainsString(
                    '<= 30 THEN 0',
                    $wpdb->lastSql,
                    'the friends arm admits nobody'
                );
                self::assertStringContainsString(
                    'uf_active_user_id = 42 THEN 1',
                    $wpdb->lastSql,
                    'except the viewer themselves, who must not vanish from their own row'
                );
                self::assertStringNotContainsString('<= 30 THEN 1', $wpdb->lastSql);
            }
        }

        public function testDuplicateAndNonPositiveFriendIdsAreDropped(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, [7, 7, 0, -2, 9], true);

            self::assertStringContainsString('IN (7,9)', $wpdb->lastSql);
        }

        /** Over the cap: deny every level-30 row, never a partial list. */
        public function testAnOversizedFriendListDeniesRatherThanTruncates(): void
        {
            $wpdb = $this->installWpdb();
            $ids  = range(1, PeepSoFollowerRepository::VIEWER_FRIEND_IDS_MAX + 1);
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, $ids, true);

            self::assertStringContainsString('<= 30 THEN 0', $wpdb->lastSql);
            self::assertStringNotContainsString('IN (1,2,3', $wpdb->lastSql);
        }

        public function testTheLadderHasNoAdmittingCatchAll(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, [7], true);

            self::assertStringContainsString('ELSE 0 END END) = 1', $wpdb->lastSql);
            self::assertStringNotContainsString('ELSE 1', $wpdb->lastSql);
        }

        // ── The ladder is RANGE-based (matches the feed ladder) ───────

        /**
         * Thresholds read out of the GENERATED SQL, in order. Not a
         * replica of the ladder: it applies only the rule SQL guarantees
         * for a searched CASE — the FIRST matching WHEN wins — to the
         * real statement. Mirrors ActivityPerPostVisibilityTest.
         *
         * @return list<int>
         */
        private static function ladderThresholds(string $sql): array
        {
            preg_match_all('/<= (\d+) THEN/', $sql, $m);
            $out = array_map('intval', $m[1]);

            self::assertNotSame([], $out, 'no range arms found in: ' . $sql);

            return $out;
        }

        /** Which rung a level lands on: first threshold >= level, null = denied. */
        private static function rungFor(int $level, string $sql): ?int
        {
            foreach (self::ladderThresholds($sql) as $threshold) {
                if ($level <= $threshold) {
                    return $threshold;
                }
            }

            return null;
        }

        private function followersSql(): string
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, [7, 9], true);

            return $wpdb->lastSql;
        }

        /**
         * `usr_profile_acc` is not a validated enum. Under the exact-match
         * CASE this gate shipped with, a stray level fell to ELSE 0 and the
         * user vanished from every follower list but their own. It must
         * land on the nearest STRICTER rung instead — the same decision as
         * the feed ladder.
         */
        public function testAStrayLevelLandsOnTheNearestStricterRung(): void
        {
            $sql = $this->followersSql();

            self::assertSame([10, 20, 30], self::ladderThresholds($sql), 'the three rungs, in order');
            self::assertSame(20, self::rungFor(15, $sql), '15 → members, not vanished');
            self::assertSame(30, self::rungFor(25, $sql), '25 → friends');
            self::assertNull(self::rungFor(35, $sql), '35 → above the top rung, denied');
            self::assertNull(self::rungFor(999, $sql));
        }

        public function testTheFourRealLevelsAreUnaffected(): void
        {
            $sql = $this->followersSql();

            self::assertSame(10, self::rungFor(10, $sql), 'public stays public');
            self::assertSame(20, self::rungFor(20, $sql), 'members stays members');
            self::assertSame(30, self::rungFor(30, $sql), 'friends stays friends');
            self::assertNull(self::rungFor(40, $sql), 'only-me stays denied');
        }

        /**
         * Every range arm compares the NORMALISED level, so NULL (no
         * PeepSo row) and 0/negatives read as public — a raw NULL would
         * match no `<=` arm and be denied.
         */
        public function testEveryArmComparesTheNormalisedLevel(): void
        {
            $sql   = $this->followersSql();
            $level = 'IF(pu.usr_profile_acc IS NULL OR pu.usr_profile_acc <= 0, 10, pu.usr_profile_acc)';

            foreach ([10, 20, 30] as $rung) {
                self::assertStringContainsString('WHEN ' . $level . ' <= ' . $rung . ' THEN', $sql);
            }
            self::assertSame(3, preg_match_all('/<= \d+ THEN/', $sql), 'exactly three range arms');
        }

        public function testTheOptionalFriendsPluginTableIsNeverJoined(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoFollowerRepository::getFollowers(5, 24, 0, 42, [7, 9], true);

            self::assertStringNotContainsString('peepso_user_friends', $wpdb->lastSql);
            self::assertStringNotContainsString('fnd_', $wpdb->lastSql);
        }

        /** The `CASE ... END END) = 1` visibility predicate, or ''. */
        private static function predicateOf(string $sql): string
        {
            $start = strpos($sql, 'AND (CASE WHEN');
            if ($start === false) {
                return '';
            }
            $end = strpos($sql, 'END END) = 1', $start);

            return $end === false ? '' : substr($sql, $start, $end - $start + 12);
        }
    }
}
