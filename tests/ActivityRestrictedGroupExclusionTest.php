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
     *   $restrictedGroupIds             — the ENFORCED group deny list.
     *   $groupGateUsesRestrictedListOnly — drops the global 'public_all'
     *                                      requirement so group access alone
     *                                      decides visibility.
     *
     * These assert the STATEMENT, not returned rows: the `$wpdb` double hands
     * back whatever it is given, so it cannot prove row filtering. What it CAN
     * prove is that the predicate reaches SQL at all — which is the failure
     * this change is most exposed to, because the neighbouring
     * `$excludedGroupIds` param is a documented no-op that would make a
     * happy-path test pass while filtering nothing. The viewer-identity
     * behaviour (member sees / non-member does not / author always does) is
     * tested in bcc-trust, where the viewer and the group-membership lookup
     * actually exist.
     */
    #[CoversClass(PeepSoActivityRepository::class)]
    final class ActivityRestrictedGroupExclusionTest extends TestCase
    {
        private const PUBLIC_ALL_GATE = "(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')";
        private const DENY_JOIN       = "LEFT JOIN wp_postmeta rg_pm ON rg_pm.post_id = p.ID AND rg_pm.meta_key = 'peepso_group_id'";

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

        /**
         * FAILS IF THE DENY PARAM IS IGNORED. Delete the implementation and
         * the join and the NOT IN both vanish from the statement.
         */
        public function testDenyListReachesSqlAsARealPredicate(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7, 9]
            );

            self::assertStringContainsString(self::DENY_JOIN, $wpdb->lastSql);
            self::assertStringContainsString(
                "(rg_pm.meta_value IS NULL OR rg_pm.meta_value NOT IN ('7','9'))",
                $wpdb->lastSql
            );
        }

        /**
         * The NULL passthrough is the whole reason this is a LEFT JOIN. An
         * INNER JOIN here would silently drop every post that is in no group
         * at all — i.e. almost the entire feed — so the clause is asserted
         * explicitly rather than inferred from the join keyword.
         */
        public function testPostsInNoGroupSurviveTheDenyList(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7]
            );

            self::assertStringContainsString('rg_pm.meta_value IS NULL OR', $wpdb->lastSql);
            self::assertStringContainsString('LEFT JOIN wp_postmeta rg_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('INNER JOIN wp_postmeta rg_pm', $wpdb->lastSql);
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

        /**
         * MUTATION CONTROL. `$excludedGroupIds` (position 9) is inert by
         * design. If someone ever "fixes" a group leak by passing the list
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
            self::assertStringNotContainsString('NOT IN (\'7\',\'9\')', $wpdb->lastSql);
            self::assertStringNotContainsString("'7'", $wpdb->lastSql);
            // The only group gate a default read carries is the global one.
            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $wpdb->lastSql);
        }

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
         * judge those rows. Remove the implementation and the gate reappears
         * here.
         */
        public function testRelaxFlagRemovesTheGlobalPublicAllGate(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, null, true
            );

            self::assertStringNotContainsString('public_all', $wpdb->lastSql);
            self::assertStringNotContainsString('gx_pm', $wpdb->lastSql);
            self::assertStringNotContainsString('vis_pm', $wpdb->lastSql);
        }

        /**
         * The pair, as the profile Blog tab sends it: group access decides
         * visibility on its own.
         */
        public function testRelaxWithDenyListGatesOnGroupAccessAlone(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                [42], ['blog'], null, null, 11, null, null, null, null, null, null, null, null, [7, 9], true
            );

            self::assertStringContainsString(
                "(rg_pm.meta_value IS NULL OR rg_pm.meta_value NOT IN ('7','9'))",
                $wpdb->lastSql
            );
            self::assertStringNotContainsString('public_all', $wpdb->lastSql);
            self::assertStringContainsString('p.post_author IN (42)', $wpdb->lastSql);
        }

        /**
         * Relaxing the gate must not widen anything when the read is already
         * scoped to one group — that path has its own gate and never consulted
         * the global one.
         */
        public function testRelaxIsANoOpOnTheGroupScopedPath(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, 42, null, null, null, null, null, [7], true
            );

            self::assertStringContainsString("gm_pm.meta_value = '42'", $wpdb->lastSql);
            // The deny list is path-independent and still applies.
            self::assertStringContainsString('rg_pm', $wpdb->lastSql);
        }

        /**
         * JOIN params render before WHERE params. The deny ids are bound as
         * WHERE params precisely so adding this join cannot shift that order;
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
            self::assertStringContainsString(
                "(rg_pm.meta_value IS NULL OR rg_pm.meta_value NOT IN ('7','9'))",
                $wpdb->lastSql
            );
            self::assertStringEndsWith('LIMIT 21', trim($wpdb->lastSql));
        }

        /**
         * meta_value is varchar, so the ids must bind as quoted strings to
         * compare against what PeepSo stores.
         */
        public function testIdsBindAsStringsBecauseMetaValueIsVarchar(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(
                null, null, null, null, 10, null, null, null, null, null, null, null, null, [7]
            );

            self::assertStringContainsString("NOT IN ('7')", $wpdb->lastSql);
            self::assertStringNotContainsString('NOT IN (7)', $wpdb->lastSql);
        }
    }
}
