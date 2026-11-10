<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\RestrictedGroups {

    use BCC\Core\Repositories\PeepSoActivityRepository;
    use BCC\Core\Tests\Stubs\RenderingWpdb;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/Stubs/rendering-wpdb.php';

    /**
     * Covers the two params the profile Blog tab needs once a blog post can
     * live inside a PeepSo group:
     *
     *   $restrictedGroupIds              — the ENFORCED group deny list.
     *   $groupGateUsesRestrictedListOnly — drops the global 'public_all'
     *                                      requirement so group access alone
     *                                      decides visibility.
     *
     * These assert the STATEMENT, not returned rows: the `$wpdb` double hands
     * back whatever it is given, so it cannot prove row filtering. What it CAN
     * prove is that the predicate reaches SQL at all — which is this change's
     * real exposure, because the neighbouring `$excludedGroupIds` param is a
     * documented no-op that would make a happy-path test pass while filtering
     * nothing. The viewer-identity behaviour (member sees it, non-member does
     * not, author always does) is tested in bcc-trust, where the viewer and
     * the group-membership lookup actually exist.
     */
    #[CoversClass(PeepSoActivityRepository::class)]
    final class ActivityRestrictedGroupExclusionTest extends TestCase
    {
        private const PUBLIC_ALL_GATE = "(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')";

        private const DENY_7_9 = "NOT EXISTS (SELECT 1 FROM wp_postmeta rg_pm WHERE rg_pm.post_id = p.ID"
            . " AND rg_pm.meta_key = 'peepso_group_id' AND rg_pm.meta_value IN ('7','9'))";

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
        // The whole claim of this change is that it is additive. A shape
        // assertion cannot carry that claim, because a stray clause added
        // anywhere else in the statement still satisfies every
        // assertStringContainsString in this file. These two pin the ENTIRE
        // statement for callers that pass neither new param, so any drift —
        // mine or a later edit's — fails here.

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
         * including the inert `$excludedGroupIds`, and neither new param.
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
         * FAILS IF THE DENY PARAM IS IGNORED. Delete the implementation and
         * the NOT EXISTS vanishes from the statement.
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
         * A post in NO group must survive. With NOT EXISTS this is free (the
         * subquery matches nothing), which is part of why it beats a join.
         */
        public function testPostsInNoGroupSurviveTheDenyList(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7]
            );

            self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM wp_postmeta rg_pm', $wpdb->lastSql);
        }

        /**
         * A post carrying TWO `peepso_group_id` rows — one restricted, one
         * allowed — must be dropped. The predicate therefore has to be
         * per-POST, not per-ROW: with a LEFT JOIN plus NOT IN the allowed row
         * satisfies the condition and SELECT DISTINCT collapses the duplicate
         * back into one surviving item, which is a silent leak. Pinned by
         * requiring the subquery form and forbidding the join form.
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

        /**
         * meta_value is varchar, so ids must bind as quoted strings.
         */
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
         * design. If someone ever patches a group leak by passing the list
         * there, nothing is filtered — and no other test in this repo would
         * notice. This pins the no-op so that mistake fails loudly here
         * instead of shipping as a working gate.
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

        // ── The relax flag, and its fail-closed guards ───────────────────

        public function testTheGlobalPublicAllGateIsThereByDefault(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

        /**
         * FAILS IF THE RELAX PARAM IS IGNORED. Under the 'public_all' gate a
         * members_only group blog is dropped for every viewer, the group's own
         * members and the author included, so the deny list never gets to
         * judge those rows. An EXPLICIT [] is the author-scoped read: every
         * row is already the viewer's own, so nothing is restricted.
         */
        public function testRelaxWithAnExplicitEmptyListRemovesTheGate(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [], true
            );

            self::assertStringNotContainsString('public_all', $wpdb->lastSql);
            self::assertStringNotContainsString('gx_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('vis_pm', $wpdb->lastSql);
        }

        public function testRelaxWithADenyListGatesOnGroupAccessAlone(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [7, 9], true
            );

            self::assertStringContainsString(self::DENY_7_9, $wpdb->lastSql);
            self::assertStringNotContainsString('public_all', $wpdb->lastSql);
            self::assertStringContainsString('p.post_author IN (42)', $wpdb->lastSql);
        }

        /**
         * FAIL-CLOSED #1. `null` means "no list supplied", which is
         * indistinguishable from "the caller's membership lookup degraded and
         * passed us nothing". Honouring the flag there would drop the gate
         * with nothing filtering in its place — every members_only and
         * secret-group post exposed. The gate must stay.
         */
        public function testRelaxIsRefusedWithoutAListSoTheGateStays(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, null, true
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
            self::assertStringNotContainsString('rg_pm', $wpdb->lastSql);
        }

        /**
         * FAIL-CLOSED #2. For an anonymous viewer the deny list is every
         * non-public group and has no ceiling, so §3 wants it bounded. Past
         * the cap the list is REFUSED rather than truncated — a truncated
         * deny list would silently stop hiding the groups that fell off the
         * end. The gate stays, which hides strictly more.
         */
        public function testAnOverLongListIsRefusedAndTheGateStays(): void
        {
            $wpdb = $this->installWpdb();

            $tooMany = range(1, PeepSoActivityRepository::RESTRICTED_GROUP_IDS_MAX + 1);

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, $tooMany, true
            );

            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
            self::assertStringNotContainsString('rg_pm', $wpdb->lastSql);
        }

        public function testAListExactlyAtTheCapIsStillHonoured(): void
        {
            $wpdb = $this->installWpdb();

            $atCap = range(1, PeepSoActivityRepository::RESTRICTED_GROUP_IDS_MAX);

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, $atCap, true
            );

            self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM wp_postmeta rg_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('public_all', $wpdb->lastSql);
        }

        /**
         * On the group-scoped path the global gate never applied, so the flag
         * must change NOTHING. Asserted by comparing the two statements
         * directly — a containment assertion here would pass whether or not
         * the flag were honoured, which is no assertion at all.
         */
        public function testRelaxChangesNothingOnTheGroupScopedPath(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, 42, null, null, null, null, null, [7], false
            );
            $withoutRelax = trim($wpdb->lastSql);

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, 42, null, null, null, null, null, [7], true
            );
            $withRelax = trim($wpdb->lastSql);

            self::assertSame($withoutRelax, $withRelax);
            self::assertStringContainsString("gm_pm.meta_value = '42'", $withRelax);
        }

        /**
         * JOIN params render before WHERE params. The deny ids are bound as
         * WHERE params precisely so this predicate cannot shift that order;
         * bound into a JOIN slot instead, a group id would land in the group
         * scope and the read would serve the wrong group's rows with no error.
         */
        public function testDenyIdsBindAsWhereParamsBehindTheJoinParams(): void
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
    }
}
