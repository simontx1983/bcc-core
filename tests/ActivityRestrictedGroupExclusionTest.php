<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\RestrictedGroups {

    use BCC\Core\Repositories\PeepSoActivityRepository;
    use BCC\Core\Tests\Stubs\RenderingWpdb;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/Stubs/rendering-wpdb.php';

    /**
     * Covers the group-visibility surface the profile Blog tab needs once a
     * blog post can live inside a PeepSo group:
     *
     *   $restrictedGroupIds   — the ENFORCED group deny list.
     *   $groupGateMode        — which visibility rule this read wants, in
     *                           place of the global 'public_all' gate.
     *   $viewerMemberGroupIds — carries members_only parity: a member sees
     *                           every visibility, a non-member only the
     *                           public two.
     *
     * These assert the STATEMENT, not returned rows: the `$wpdb` double
     * hands back whatever it is given, so it cannot prove row filtering.
     * What it CAN prove is which predicates reach SQL — which is this
     * change's real exposure, because the neighbouring `$excludedGroupIds`
     * param is a documented no-op that would make a happy-path test pass
     * while filtering nothing. The viewer-identity behaviour (member sees
     * it, non-member does not, author always does) is tested in bcc-trust,
     * where the viewer and the group-membership lookup actually exist.
     */
    #[CoversClass(PeepSoActivityRepository::class)]
    final class ActivityRestrictedGroupExclusionTest extends TestCase
    {
        private const PUBLIC_ALL_GATE = "(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')";

        private const DENY_7_9 = "NOT EXISTS (SELECT 1 FROM wp_postmeta rg_pm WHERE rg_pm.post_id = p.ID"
            . " AND rg_pm.meta_key = 'peepso_group_id' AND rg_pm.meta_value IN ('7','9'))";

        private const NO_GROUP_ALT = "NOT EXISTS (SELECT 1 FROM wp_postmeta mg_abs WHERE mg_abs.post_id = p.ID"
            . " AND mg_abs.meta_key = 'peepso_group_id')";

        private const MEMBER_ALT = "EXISTS (SELECT 1 FROM wp_postmeta mg_pm WHERE mg_pm.post_id = p.ID"
            . " AND mg_pm.meta_key = 'peepso_group_id' AND mg_pm.meta_value IN ('3','4'))";

        // Per-post: a public row must exist AND no non-public row may.
        private const PUBLIC_VIS_ALT = "(EXISTS (SELECT 1 FROM wp_postmeta nmv_pm WHERE nmv_pm.post_id = p.ID"
            . " AND nmv_pm.meta_key = '_bcc_post_visibility'"
            . " AND nmv_pm.meta_value IN ('public_group','public_all'))"
            . " AND NOT EXISTS (SELECT 1 FROM wp_postmeta nmv_neg WHERE nmv_neg.post_id = p.ID"
            . " AND nmv_neg.meta_key = '_bcc_post_visibility'"
            . " AND nmv_neg.meta_value NOT IN ('public_group','public_all')))";

        private const SELECT_PREFIX = 'SELECT DISTINCT a.act_id, p.post_author AS act_user_id, a.act_owner_id,'
            . ' a.act_module_id, a.act_external_id, p.post_date_gmt AS act_time, a.act_access,'
            . ' p.post_status AS act_status FROM wp_peepso_activities a'
            . ' INNER JOIN wp_posts p ON p.ID = a.act_external_id';

        private const GLOBAL_GATE_JOINS = " LEFT JOIN wp_postmeta gx_pm ON gx_pm.post_id = p.ID"
            . " AND gx_pm.meta_key = 'peepso_group_id' LEFT JOIN wp_postmeta vis_pm ON vis_pm.post_id = p.ID"
            . " AND vis_pm.meta_key = '_bcc_post_visibility'";

        private const BASE_WHERE = " WHERE p.post_status = 'publish'"
            . ' AND (a.act_comment_object_id = 0 OR a.act_comment_object_id IS NULL)';

        private const DEFAULT_MODULES = ' AND a.act_module_id IN (1,4,200,201,202,203,204,205)';

        private const TAIL = ' ORDER BY p.post_date_gmt DESC, a.act_id DESC LIMIT ';

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

        // ── Golden snapshots: "every other caller is byte-identical" ─────
        //
        // The claim of this change is that it is additive. A containment
        // assertion cannot carry that claim, because a stray clause added
        // anywhere else still satisfies every assertStringContainsString in
        // this file. These two pin the ENTIRE statement for callers that
        // pass none of the new params, so any drift fails here.

        public function testGoldenSqlForADefaultCallIsUnchanged(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertSame(
                self::SELECT_PREFIX . self::GLOBAL_GATE_JOINS . self::BASE_WHERE
                    . self::DEFAULT_MODULES . ' AND ' . self::PUBLIC_ALL_GATE . self::TAIL . '10',
                trim($wpdb->lastSql)
            );
        }

        /**
         * The shape ActivityFeedService sends: every legacy param populated,
         * including the inert `$excludedGroupIds`, and none of the new ones.
         */
        public function testGoldenSqlForAFeedServiceShapedCallIsUnchanged(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null,
                ['status', 'blog'],
                '2026-11-10 10:00:00',
                900,
                21,
                [11, 12],
                [13],
                null,
                [5, 6],
                null,
                null,
                null,
                null
            );

            self::assertSame(
                self::SELECT_PREFIX . self::GLOBAL_GATE_JOINS . self::BASE_WHERE
                    . ' AND p.post_author NOT IN (11,12)'
                    . ' AND a.act_id NOT IN (13)'
                    . " AND a.act_module_id IN ('status','blog')"
                    . " AND (p.post_date_gmt < '2026-11-10 10:00:00'"
                    . " OR (p.post_date_gmt = '2026-11-10 10:00:00' AND a.act_id < 900))"
                    . ' AND ' . self::PUBLIC_ALL_GATE . self::TAIL . '21',
                trim($wpdb->lastSql)
            );
        }

        // ── The deny list ────────────────────────────────────────────────

        /**
         * FAILS IF THE DENY PARAM IS IGNORED.
         */
        public function testDenyListReachesSqlAsARealPredicate(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7, 9]
            );

            self::assertStringContainsString(self::DENY_7_9, $wpdb->lastSql);
        }

        /**
         * A post carrying TWO `peepso_group_id` rows — one restricted, one
         * allowed — must be dropped, so the predicate has to be per-POST.
         * With a LEFT JOIN plus NOT IN the allowed row satisfies the
         * condition and SELECT DISTINCT collapses the duplicate back into a
         * surviving item: a silent leak. Pinned by requiring the subquery
         * form and forbidding the join form.
         */
        public function testExclusionIsPerPostNotPerMetaRow(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7, 9]
            );

            self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM wp_postmeta rg_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('LEFT JOIN wp_postmeta rg_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('rg_pm.meta_value NOT IN', $wpdb->lastSql);
        }

        public function testNoDenyPredicateWhenTheParamIsOmittedOrEmpty(): void
        {
            foreach ([null, []] as $ids) {
                $wpdb = $this->installWpdb();

                PeepSoActivityRepository::getActivities(
                    null, null, null, null, 10, null, null, null, null, null, null, null, null, $ids
                );

                self::assertStringNotContainsString('rg_pm', $wpdb->lastSql);
            }
        }

        public function testDuplicateIdsAreCollapsedBeforeBinding(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7, 7, 9, 7]
            );

            self::assertStringContainsString("rg_pm.meta_value IN ('7','9')", $wpdb->lastSql);
        }

        public function testIdsBindAsStringsBecauseMetaValueIsVarchar(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7]
            );

            self::assertStringContainsString("IN ('7')", $wpdb->lastSql);
            self::assertStringNotContainsString('IN (7)', $wpdb->lastSql);
        }

        /**
         * MUTATION CONTROL. `$excludedGroupIds` (position 9) is inert by
         * design. If someone patches a group leak by passing the list there,
         * nothing is filtered — and no other test in this repo would notice.
         */
        public function testTheInertExcludedGroupIdsParamStillFiltersNothing(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, [7, 9], null, null, null, null, null
            );

            self::assertStringNotContainsString('rg_pm', $wpdb->lastSql);
            self::assertStringNotContainsString("'7'", $wpdb->lastSql);
            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

        // ── $groupGateMode, and its fail-closed guards ───────────────────

        public function testTheGlobalPublicAllGateIsThereByDefault(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

        /**
         * FAILS IF THE MODE IS IGNORED. Under the 'public_all' gate a
         * members_only group blog is dropped for every viewer, the group's
         * own members and the author included, so the deny list never gets
         * to judge those rows.
         */
        public function testViewerAccessWithAUsableListRemovesTheGate(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7, 9],
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, [3, 4]
            );

            self::assertStringNotContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
            self::assertStringNotContainsString('gx_pm', $wpdb->lastSql);
            self::assertStringContainsString(self::DENY_7_9, $wpdb->lastSql);
        }

        /**
         * FAIL-CLOSED #1 — a NULL list. null means "no list supplied", which
         * is indistinguishable from "my membership lookup failed and I am
         * passing you nothing". Honouring the mode there would drop the gate
         * with nothing in its place, exposing every members_only and
         * secret-group post.
         */
        public function testViewerAccessIsRefusedWithoutAListSoTheGateStays(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, null,
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, null
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
            self::assertStringNotContainsString('rg_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('nmv_pm', $wpdb->lastSql);
        }

        /**
         * FAIL-CLOSED #2 — an over-long list is REFUSED, not truncated. A
         * truncated deny list silently stops hiding whatever fell off the
         * end.
         */
        public function testAnOverCapListIsRefusedAndTheGateStays(): void
        {
            $wpdb = $this->installWpdb();

            $tooMany = range(1, PeepSoActivityRepository::RESTRICTED_GROUP_IDS_MAX + 1);

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, $tooMany,
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, null
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
            self::assertStringNotContainsString('rg_pm', $wpdb->lastSql);
        }

        public function testAListExactlyAtTheCapIsStillHonoured(): void
        {
            $wpdb = $this->installWpdb();

            $atCap = range(1, PeepSoActivityRepository::RESTRICTED_GROUP_IDS_MAX);

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, $atCap,
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, null
            );

            self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM wp_postmeta rg_pm', $wpdb->lastSql);
            self::assertStringNotContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

        /**
         * FAIL-CLOSED #3 — an unrecognised mode relaxes nothing. This is the
         * reason the param is a named mode and not a boolean: a typo, or a
         * value from a future branch, must not widen a read.
         */
        public function testAnUnrecognisedModeRelaxesNothing(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7],
                'nonsense-mode', null
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
            self::assertStringNotContainsString('nmv_pm', $wpdb->lastSql);
        }

        /**
         * FAIL-CLOSED #4 — the group-scoped path has no gate behind the deny
         * list, so a SUPPLIED-but-unusable list must refuse the read rather
         * than serve the group unfiltered. Nothing is even queried.
         */
        public function testGroupScopedReadIsRefusedWhenASuppliedListIsUnusable(): void
        {
            $wpdb = $this->installWpdb();

            $tooMany = range(1, PeepSoActivityRepository::RESTRICTED_GROUP_IDS_MAX + 1);

            $rows = PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, 42, null, null, null, null, null, $tooMany,
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, null
            );

            self::assertSame([], $rows);
            self::assertSame(0, $wpdb->queryCount);
        }

        /**
         * The author reading their own rows needs no deny list — every row
         * the query can return is already theirs — and keeps seeing them
         * after leaving the group. This is the only way to relax the gate
         * without a list, and it must be asked for by name.
         */
        public function testAuthorScopedNeedsNoListAndDropsEveryGroupPredicate(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [],
                PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, null, 42
            );

            self::assertStringNotContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
            self::assertStringNotContainsString('rg_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('nmv_pm', $wpdb->lastSql);
            self::assertStringContainsString('p.post_author IN (42)', $wpdb->lastSql);
        }

        // ── members_only parity (the group feed's rule) ──────────────────

        /**
         * FAILS IF THE PARITY CLAUSE IS IGNORED. The deny list removes groups
         * the viewer may not SEE; it says nothing about an OPEN group they
         * can see but have not joined. members_only has to mean members_only
         * there too, exactly as the group feed already enforces for a
         * non-member.
         */
        public function testViewerAccessAppliesMembersOnlyParity(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [7, 9],
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, [3, 4]
            );

            self::assertStringContainsString(
                '(' . self::NO_GROUP_ALT . ' OR ' . self::MEMBER_ALT . ' OR ' . self::PUBLIC_VIS_ALT . ')',
                $wpdb->lastSql
            );
        }

        /**
         * An anonymous reader belongs to nothing, so the membership
         * alternative is omitted and only the public visibilities qualify.
         * The no-group alternative must survive, or every non-group blog
         * would vanish from every profile.
         */
        public function testAnonymousViewerGetsNoMembershipAlternative(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [7, 9],
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, null
            );

            self::assertStringContainsString(
                '(' . self::NO_GROUP_ALT . ' OR ' . self::PUBLIC_VIS_ALT . ')',
                $wpdb->lastSql
            );
            self::assertStringNotContainsString('mg_pm', $wpdb->lastSql);
        }

        /**
         * An over-cap membership list drops the membership alternative. That
         * costs a member sight of their own groups' members_only posts —
         * restrictive, not leaky — and keeps the IN list bounded.
         */
        public function testOverCapMembershipListDropsTheMembershipAlternative(): void
        {
            $wpdb = $this->installWpdb();

            $tooManyGroups = range(1, PeepSoActivityRepository::RESTRICTED_GROUP_IDS_MAX + 1);

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [7],
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, $tooManyGroups
            );

            self::assertStringNotContainsString('mg_pm', $wpdb->lastSql);
            self::assertStringContainsString(self::PUBLIC_VIS_ALT, $wpdb->lastSql);
        }

        /**
         * Absent visibility meta must NOT qualify — absent means
         * members_only. Pinned by requiring EXISTS (not a LEFT JOIN with a
         * NULL passthrough) on the visibility alternative.
         */
        public function testAbsentVisibilityMetaDoesNotQualifyAsPublic(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [7],
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, null
            );

            self::assertStringContainsString('OR (EXISTS (SELECT 1 FROM wp_postmeta nmv_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('nmv_pm.meta_value IS NULL', $wpdb->lastSql);
            self::assertStringNotContainsString('LEFT JOIN wp_postmeta nmv_pm', $wpdb->lastSql);
        }

        // ── Ordering / no-op guarantees ──────────────────────────────────

        /**
         * On the group-scoped path the global gate never applied, so the mode
         * must change NOTHING. Compared directly: a containment assertion
         * would pass whether or not the mode were honoured.
         */
        public function testModeChangesNothingOnTheGroupScopedPath(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, 42, null, null, null, null, null, [7], null
            );
            $withoutMode = trim($wpdb->lastSql);

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, 42, null, null, null, null, null, [7],
                PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED
            );
            $withMode = trim($wpdb->lastSql);

            self::assertSame($withoutMode, $withMode);
            self::assertStringContainsString("gm_pm.meta_value = '42'", $withMode);
        }

        /**
         * JOIN params render before WHERE params. Every new predicate binds
         * as a WHERE param precisely so it cannot shift that order; bound
         * into a JOIN slot instead, a group id would land in the group scope
         * and the read would serve the wrong group's rows with no error.
         */
        public function testNewPredicatesBindAsWhereParamsBehindTheJoinParams(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null,
                null,
                '2026-11-10 10:00:00',
                900,
                21,
                [11, 12],
                [13],
                42,
                null,
                ['public_group', 'public_all'],
                null,
                null,
                null,
                [7, 9]
            );

            self::assertStringContainsString("gm_pm.meta_value = '42'", $wpdb->lastSql);
            self::assertStringContainsString(
                "vis_in_pm.meta_value IN ('public_group','public_all')",
                $wpdb->lastSql
            );
            self::assertStringContainsString('p.post_author NOT IN (11,12)', $wpdb->lastSql);
            self::assertStringContainsString('a.act_id NOT IN (13)', $wpdb->lastSql);
            self::assertStringContainsString(self::DENY_7_9, $wpdb->lastSql);
            self::assertStringEndsWith('LIMIT 21', trim($wpdb->lastSql));
        }

        /**
         * The parity clause binds member ids BEFORE the visibility values,
         * in the order the alternatives appear. Swapped, a group id would
         * render into the visibility IN list and vice versa, which the
         * rendering double catches as a mismatch.
         */

        // ── AUTHOR_SCOPED must PROVE itself ─────────────────────────────

        /**
         * FAILS IF THE PROOF IS DROPPED. The mode is only a string, so on
         * its own it would let any caller drop the gate on a site-wide read
         * with no deny list and no parity clause — the widest hole in the
         * whole surface, opened by one argument. These four pin that the
         * repository checks the premise instead of trusting the label.
         */
        public function testAuthorScopedRelaxesNothingWithoutAViewer(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, null,
                PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, null, 0
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

        public function testAuthorScopedRelaxesNothingWhenTheViewerIsNotTheAuthor(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, null,
                PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, null, 7
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

        public function testAuthorScopedRelaxesNothingOnAMultiAuthorRead(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42, 43], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, null,
                PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, null, 42
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

        /**
         * A site-wide read ($authorIds null) claiming author scope is the
         * dangerous shape: it would expose every group post on the site.
         */
        public function testAuthorScopedRelaxesNothingOnASiteWideRead(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, null,
                PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, null, 42
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

        public function testAuthorScopedIsHonouredWhenTheViewerIsProvenToBeTheAuthor(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, null,
                PeepSoActivityRepository::GROUP_GATE_AUTHOR_SCOPED, null, 42
            );

            self::assertStringNotContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
            self::assertStringContainsString('p.post_author IN (42)', $wpdb->lastSql);
        }

        // ── The two LOWs ────────────────────────────────────────────────

        /**
         * The visibility test must be PER POST. A post with duplicate
         * visibility rows {members_only, public_group} would satisfy a bare
         * EXISTS on the public values and be shown — the same per-row
         * mistake the deny list had. Pinned by requiring the negative
         * subquery alongside the positive one.
         */
        public function testVisibilityAlternativeIsPerPostNotAnyRow(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [7],
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, null
            );

            self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM wp_postmeta nmv_neg', $wpdb->lastSql);
            self::assertStringContainsString(
                "nmv_neg.meta_value NOT IN ('public_group','public_all')",
                $wpdb->lastSql
            );
        }

        /**
         * The parity clause is documented as global-path only, and the
         * group-scoped path already receives $groupVisibilityIn from its
         * caller. Code and docblock must agree.
         */
        public function testParityClauseIsNotAddedOnTheGroupScopedPath(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, 42, null, null, null, null, null, [7],
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, [3, 4]
            );

            self::assertStringNotContainsString('nmv_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('mg_abs', $wpdb->lastSql);
            self::assertStringContainsString("gm_pm.meta_value = '42'", $wpdb->lastSql);
        }

        public function testParityClauseBindsMemberIdsBeforeVisibilityValues(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [7],
                PeepSoActivityRepository::GROUP_GATE_VIEWER_ACCESS, [3, 4]
            );

            self::assertStringContainsString("mg_pm.meta_value IN ('3','4')", $wpdb->lastSql);
            self::assertStringContainsString(
                "nmv_pm.meta_value IN ('public_group','public_all')",
                $wpdb->lastSql
            );
        }
    }
}
