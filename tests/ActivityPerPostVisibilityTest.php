<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\PerPostVisibility {

    use BCC\Core\Repositories\PeepSoActivityRepository;
    use BCC\Core\Tests\Stubs\RenderingWpdb;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/Stubs/rendering-wpdb.php';

    /**
     * §F-02 per-post effective visibility: a post is visible at the
     * STRICTER of its own `act_access` and its AUTHOR's
     * `usr_profile_acc`, so a profile set to "friends" or "only me"
     * retroactively narrows the posts already written under it.
     *
     * These assert the STATEMENT, not returned rows — the `$wpdb` double
     * echoes back whatever it is handed, so it cannot prove row
     * filtering. What it CAN prove is the thing most likely to be wrong
     * and least likely to be noticed: that the right predicate reaches
     * SQL with its PARAMETERS BOUND IN THE RIGHT SLOTS.
     *
     * ⚠⚠ WHY BINDING IS THE REAL RISK HERE. `getActivities` assembles
     * `$joinParams`, then `$params`, then the limit, and matches them to
     * `%d`/`%s` placeholders POSITIONALLY, by hand, across a dozen
     * optional clauses. A mismatch does not throw — it binds a viewer id
     * where a friend id belongs and silently changes who can see what.
     * The double renders parameters INTO the SQL, so these assertions
     * read the bound VALUES rather than trusting the author's count.
     */
    #[CoversClass(PeepSoActivityRepository::class)]
    final class ActivityPerPostVisibilityTest extends TestCase
    {
        /** The join is PeepSo CORE's users table — not the optional friends plugin's. */
        private const AUTHOR_JOIN = 'LEFT JOIN wp_peepso_users pu ON pu.usr_id = p.post_author';

        private const EFFECTIVE_LEVEL = 'GREATEST(IF(a.act_access IS NULL OR a.act_access <= 0, 10, a.act_access),'
            . ' COALESCE(pu.usr_profile_acc, 10))';

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

        /**
         * @param list<int>|null $friendIds
         */
        private function sqlFor(int $viewerId, ?array $friendIds, bool $enforce): string
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null,
                null,
                null,
                null,
                5,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                $viewerId,
                $friendIds,
                $enforce
            );

            return trim($wpdb->lastSql);
        }

        /**
         * The same question asked of the SINGLE-post reader.
         *
         * Named arguments on purpose: `getById()`'s visibility
         * parameters sit behind `$allowedModuleIds`, and this mirrors how
         * the service calls it.
         *
         * @param list<int>|null $friendIds
         */
        private function sqlForById(int $viewerId, ?array $friendIds, bool $enforce): string
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getById(
                4242,
                null,
                viewerFriendIds: $friendIds,
                enforcePostVisibility: $enforce,
                viewerId: $viewerId
            );

            return trim($wpdb->lastSql);
        }

        /** Extract the F-02 predicate from a rendered statement. */
        private static function predicateOf(string $sql): string
        {
            $start = strpos($sql, 'CASE WHEN');
            self::assertNotFalse($start, 'no F-02 predicate in: ' . $sql);
            $end = strpos($sql, ') = 1', $start);
            self::assertNotFalse($end, 'unterminated F-02 predicate');

            return substr($sql, $start, $end - $start + 5);
        }

        // ── the single-post reader ─────────────────────────────────────

        /**
         * ⚠⚠ THE POINT OF SHARING ONE PREDICATE. A permalink that served
         * a post the feed hides — or hid one the feed serves — would be
         * worse than either behaviour on its own, and nothing else in the
         * suite would notice: both queries would still look correct read
         * separately. So this compares them BYTE FOR BYTE.
         */
        public function testThePermalinkPredicateIsIdenticalToTheFeedPredicate(): void
        {
            $viewer  = 7;
            $friends = [11, 12];

            self::assertSame(
                self::predicateOf($this->sqlFor($viewer, $friends, true)),
                self::predicateOf($this->sqlForById($viewer, $friends, true)),
                'the list read and the single-post read must apply the SAME rule'
            );
        }

        /**
         * The opt-in claim again, for the new reader: every pre-existing
         * caller of `getById()` — author resolution, comment parents,
         * moderation, interaction gating — passes nothing, and must get a
         * byte-identical query.
         */
        public function testGetByIdAddsNothingWhenTheSwitchIsOff(): void
        {
            $sql = $this->sqlForById(7, [11, 12], false);

            self::assertStringNotContainsString('peepso_users', $sql);
            self::assertStringNotContainsString('usr_profile_acc', $sql);
            self::assertStringNotContainsString('CASE', $sql);
        }

        /**
         * ⚠ The DEFAULT, not an explicitly-passed false. Every
         * pre-existing caller — author resolution, comment-parent
         * lookup, moderation, interaction gating — calls
         * `getById($actId)` or `getById($actId, $modules)` and passes
         * nothing about visibility. Flipping the default would silently
         * start gating all of them, and a test that passes `false` by
         * hand would not notice: found by mutation, which survived until
         * this existed.
         */
        public function testGetByIdIsUngatedForACallerThatPassesNothing(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getById(4242);

            $sql = trim($wpdb->lastSql);
            self::assertStringNotContainsString('peepso_users', $sql);
            self::assertStringNotContainsString('usr_profile_acc', $sql);
            self::assertStringNotContainsString('CASE', $sql);
            self::assertStringContainsString('a.act_id = 4242', $sql);
        }

        /** The join has to be present for the predicate to mean anything. */
        public function testGetByIdJoinsTheAuthorLevelWhenEnforcing(): void
        {
            $sql = $this->sqlForById(7, null, true);

            self::assertStringContainsString('LEFT JOIN', $sql);
            self::assertStringContainsString('peepso_users pu', $sql);
            self::assertStringContainsString('pu.usr_id = p.post_author', $sql);
        }

        /**
         * Same positional-binding risk as the list read: the own-post
         * guard reads the viewer twice, the members branch once, then the
         * friend ids. A slip binds a viewer id where a friend id belongs
         * and silently changes who can see what.
         */
        public function testGetByIdBindsTheViewerAndFriendIdsIntoTheCorrectSlots(): void
        {
            $sql = $this->sqlForById(7, [11, 12], true);

            self::assertStringContainsString('CASE WHEN 7 > 0 AND p.post_author = 7', $sql);
            self::assertStringContainsString('<= 20 THEN IF(7 > 0, 1, 0)', $sql);
            self::assertStringContainsString('p.post_author IN (11,12)', $sql);
        }

        /**
         * Control: the act_id still binds. If the new parameters had
         * displaced it positionally, every assertion above would still
         * pass while the query fetched the wrong row.
         */
        public function testGetByIdStillBindsTheActIdItWasAskedFor(): void
        {
            $sql = $this->sqlForById(7, null, true);

            self::assertStringContainsString('a.act_id = 4242', $sql);
        }

        // ── OFF by default: the additive claim ─────────────────────────

        /**
         * The claim of this change is that it is opt-in. A containment
         * assertion cannot carry that — a stray clause elsewhere still
         * passes every assertStringContainsString. So: with the switch
         * off, NEITHER the join NOR the predicate may appear at all.
         */
        public function testNothingIsAddedWhenTheSwitchIsOff(): void
        {
            $sql = $this->sqlFor(42, [7, 9], false);

            self::assertStringNotContainsString(self::AUTHOR_JOIN, $sql, 'no join when off');
            self::assertStringNotContainsString('GREATEST(', $sql, 'no predicate when off');
            self::assertStringNotContainsString('usr_profile_acc', $sql, 'the column is not even mentioned');
        }

        public function testFriendIdsAreIgnoredEntirelyWhenTheSwitchIsOff(): void
        {
            // Control for the test above: friend ids present but unused
            // must not leak into the statement as stray bound values.
            $sql = $this->sqlFor(42, [7, 9], false);

            self::assertStringNotContainsString('IN (7,9)', $sql);
        }

        // ── ON: the predicate, and its BOUND VALUES ───────────────────

        public function testTheEffectiveLevelIsTheStricterOfPostAndAuthor(): void
        {
            $sql = $this->sqlFor(42, [7, 9], true);

            self::assertStringContainsString(self::AUTHOR_JOIN, $sql);
            // GREATEST is what makes it "stricter wins": the ladder is
            // ordered 10 < 20 < 30 < 40, so the higher value governs.
            self::assertStringContainsString(self::EFFECTIVE_LEVEL, $sql);
        }

        /**
         * ⚠ THE BINDING TEST. Every parameter this clause contributes is
         * asserted by its RENDERED VALUE, in order: the own-post guard
         * reads the viewer twice, the members branch once, then the
         * friend ids. If the hand-maintained param list drifted from the
         * placeholders, these values would appear in the wrong slots.
         */
        public function testViewerAndFriendIdsBindIntoTheCorrectSlots(): void
        {
            $sql = $this->sqlFor(42, [7, 9], true);

            self::assertStringContainsString(
                'CASE WHEN 42 > 0 AND p.post_author = 42 THEN 1',
                $sql,
                'the own-post guard must bind the viewer id twice'
            );
            self::assertStringContainsString(
                '<= 20 THEN IF(42 > 0, 1, 0)',
                $sql,
                'the members-only branch must bind the viewer id, not a friend id'
            );
            self::assertStringContainsString(
                '<= 30 THEN IF(p.post_author IN (7,9), 1, 0)',
                $sql,
                'the friends branch must bind the friend ids, in order, and nothing else'
            );
            self::assertStringContainsString('ELSE 0 END END) = 1', $sql, '40 and anything unknown deny');
        }

        /**
         * An unrecognised effective level must DENY. This is the inverse
         * of the defect found in the profile-level gate, where an
         * unreadable value cast to 0 and 0 meant PUBLIC.
         */
        public function testTheLadderHasNoAdmittingCatchAll(): void
        {
            $sql = $this->sqlFor(42, [7, 9], true);

            // The only admitting arms are the enumerated ones.
            self::assertSame(
                1,
                substr_count($sql, '<= 10 THEN 1'),
                'exactly one admitting arm for public'
            );
            self::assertStringNotContainsString('ELSE 1', $sql, 'nothing admits by default');
        }

        // ── The ladder is RANGE-based (the stray-level decision) ──────

        /**
         * Read the ladder's thresholds out of the GENERATED SQL, in the
         * order they appear.
         *
         * ⚠ This does not re-implement the ladder — that would test a
         * replica. It takes the real thresholds from the real statement
         * and applies the one rule SQL guarantees: in a searched CASE,
         * the FIRST matching WHEN wins. bcc-core has no MySQL-backed
         * suite, so this is the closest thing to evaluating the
         * expression that a unit test can honestly claim.
         *
         * @return list<int>
         */
        private function ladderThresholds(string $sql): array
        {
            preg_match_all('/<= (\d+) THEN/', $sql, $m);
            $out = array_map('intval', $m[1]);

            self::assertNotSame([], $out, 'no range arms found in: ' . $sql);

            return $out;
        }

        /** Which rung an effective level lands on: first threshold >= level. */
        private function rungFor(int $level, string $sql): ?int
        {
            foreach ($this->ladderThresholds($sql) as $threshold) {
                if ($level <= $threshold) {
                    return $threshold;
                }
            }

            return null; // falls to ELSE 0 — denied
        }

        /**
         * ⚠⚠ THE REASON THIS LADDER IS RANGE-BASED. `act_access` is NOT a
         * validated enum: PeepSo's `change_post_privacy` writes it
         * straight from `$this->_input->int('acc')` with no check against
         * {10,20,30,40}. Under the exact-match CASE this gate shipped
         * with, every stray value fell to `ELSE 0` and the post became
         * invisible to everyone but its author — silently, and site-wide
         * the day a PeepSo upgrade introduced a new level.
         *
         * A stray level must land on the nearest STRICTER rung instead.
         */
        public function testAStrayLevelLandsOnTheNearestStricterRung(): void
        {
            $sql = $this->sqlFor(42, [7, 9], true);

            self::assertSame([10, 20, 30], $this->ladderThresholds($sql), 'the three rungs, in order');

            // 15 sits between public and members: members, not vanished.
            self::assertSame(20, $this->rungFor(15, $sql));
            // 25 sits between members and friends: friends.
            self::assertSame(30, $this->rungFor(25, $sql));
            // 35 sits between friends and private: above the last rung,
            // so denied — correct, it is closer to "only me" than to
            // "friends", and widening must never admit what 40 hid.
            self::assertNull($this->rungFor(35, $sql));
            // An unknown high value stays denied. 255 rather than a
            // larger number because `act_access` is
            // TINYINT(3) UNSIGNED: 255 is the real ceiling, and a test
            // pinning 999 pins an input the column cannot hold.
            self::assertNull($this->rungFor(255, $sql));
        }

        /**
         * The rungs that were already correct must not move. 10, 20, 30
         * and 40 behaved correctly under exact-match too, so this change
         * is only allowed to affect the values BETWEEN them.
         */
        public function testTheFourRealLevelsAreUnaffectedByTheWidening(): void
        {
            $sql = $this->sqlFor(42, [7, 9], true);

            self::assertSame(10, $this->rungFor(10, $sql), 'public stays public');
            self::assertSame(20, $this->rungFor(20, $sql), 'members stays members');
            self::assertSame(30, $this->rungFor(30, $sql), 'friends stays friends');
            self::assertNull($this->rungFor(40, $sql), 'only-me stays denied');
        }

        /**
         * Control: the widening must not have become a catch-all. If the
         * last arm admitted instead of denying, every assertNull above
         * would flip and a level-40 post would be world-readable.
         */
        public function testNothingAdmitsAboveTheTopRung(): void
        {
            $sql = $this->sqlFor(42, [7, 9], true);

            self::assertStringContainsString('ELSE 0 END END', $sql, 'the ladder still ends in a deny');
            self::assertStringNotContainsString('ELSE 1', $sql);
        }

        // ── Degenerate inputs: no empty IN(), and fail closed ─────────

        /**
         * An empty `IN ()` is a SQL syntax error, so the friends branch
         * must collapse to a literal 0 rather than be built from an empty
         * list. Anonymous readers are the common case here.
         */
        public function testAnonymousReaderGetsALiteralZeroFriendsBranch(): void
        {
            $sql = $this->sqlFor(0, null, true);

            self::assertStringContainsString('<= 30 THEN 0', $sql, 'literal 0, never IN ()');
            self::assertStringNotContainsString('IN ()', $sql);
            self::assertStringContainsString(
                '<= 20 THEN IF(0 > 0, 1, 0)',
                $sql,
                'members-only denies an anonymous reader'
            );
        }

        public function testASignedInViewerWithNoFriendsAlsoGetsALiteralZero(): void
        {
            $sql = $this->sqlFor(42, [], true);

            self::assertStringContainsString('<= 30 THEN 0', $sql);
            self::assertStringNotContainsString('IN ()', $sql);
            // ...but still sees their OWN posts at any level.
            self::assertStringContainsString('p.post_author = 42 THEN 1', $sql);
        }

        /**
         * PRODUCT DECISION (user, 2026-11-11): BCC has no friends UI, so
         * the frontend hides "Friends only". Level 30 stays valid
         * server-side, and with an empty friends list must behave exactly
         * like "Only me" — owner and admins, nobody else.
         *
         * Named explicitly so that decision cannot be quietly relaxed: a
         * future change that made an empty level-30 list admit signed-in
         * viewers, or fall through to the public arm, fails here rather
         * than silently publishing posts the author expected to be
         * private.
         *
         * Admins are not in this assertion because they are not expressed
         * in SQL — an admin read passes `$enforcePostVisibility = false`,
         * which the switch-off tests above cover.
         */
        public function testLevel30WithNoFriendsAdmitsNobodyButTheOwner(): void
        {
            $sql = $this->sqlFor(42, [], true);

            self::assertStringContainsString('<= 30 THEN 0', $sql, 'nobody is admitted by the friends arm');
            self::assertStringContainsString(
                'CASE WHEN 42 > 0 AND p.post_author = 42 THEN 1',
                $sql,
                'except the owner, whose own posts stay visible'
            );
            self::assertStringNotContainsString('<= 30 THEN 1', $sql, 'the arm never admits unconditionally');
            self::assertStringNotContainsString('<= 40', $sql, 'there is no 40 arm at all — it falls to ELSE 0');
        }

        /**
         * A degraded friends read must deny, not open up. bcc-trust owns
         * that read, and whatever it fails with — `null` or `[]` — arrives
         * here as "a friend of nobody" and collapses to the same literal
         * 0. Asserted for both shapes because they come from different
         * failure paths upstream.
         */
        public function testADegradedFriendsReadFailsClosed(): void
        {
            foreach ([null, []] as $degraded) {
                $sql = $this->sqlFor(42, $degraded, true);
                self::assertStringContainsString(
                    '<= 30 THEN 0',
                    $sql,
                    'a failed friends lookup must deny level 30, never admit it'
                );
            }
        }

        public function testNonPositiveAndDuplicateFriendIdsAreDropped(): void
        {
            $sql = $this->sqlFor(42, [7, 7, 0, -3, 9], true);

            self::assertStringContainsString('IN (7,9)', $sql, 'deduped, integer-cast, junk removed');
        }

        /**
         * ⚠ Over the cap the list is treated as EMPTY, which DENIES every
         * level-30 post. Truncating would admit the first N friends and
         * deny the rest with no signal — a gate whose behaviour depends
         * on array order.
         */
        public function testAnOversizedFriendListDeniesRatherThanTruncates(): void
        {
            $ids = range(1, PeepSoActivityRepository::VIEWER_FRIEND_IDS_MAX + 1);
            $sql = $this->sqlFor(42, $ids, true);

            self::assertStringContainsString('<= 30 THEN 0', $sql, 'denied, not truncated');
            self::assertStringNotContainsString('IN (1,2,3', $sql, 'no partial list reaches SQL');
        }

        public function testExactlyAtTheCapIsStillHonoured(): void
        {
            // Pins the boundary as <=, so the cap cannot drift off by one
            // and silently start denying a legitimate list.
            $ids = range(1, PeepSoActivityRepository::VIEWER_FRIEND_IDS_MAX);
            $sql = $this->sqlFor(42, $ids, true);

            self::assertStringContainsString('<= 30 THEN IF(p.post_author IN (1,2,3', $sql);
        }

        /**
         * The friends table is PeepSo's optional add-on. Joining it would
         * make the main feed query fail wherever that plugin is absent,
         * so bcc-core must never reference it — the ids come from
         * bcc-trust instead.
         */
        public function testTheOptionalFriendsPluginTableIsNeverJoined(): void
        {
            $sql = $this->sqlFor(42, [7, 9], true);

            self::assertStringNotContainsString('peepso_user_friends', $sql);
            self::assertStringNotContainsString('fnd_', $sql);
        }
    }
}
