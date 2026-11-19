<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\MyGroups {

    use BCC\Core\Contracts\ActivityReadFailedException;
    use BCC\Core\Feed\ActivityFeedService;
    use BCC\Core\Repositories\PeepSoActivityRepository;
    use BCC\Core\Repositories\PeepSoGroupRepository;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\CoversMethod;
    use PHPUnit\Framework\TestCase;

    /**
     * `$wpdb` double for this file: renders placeholders, and answers the
     * two membership reads and the activity read separately, so one test
     * can drive the whole "My Groups" path.
     */
    final class MyGroupsWpdb
    {
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $prefix   = 'wp_';

        public string $last_error = '';

        /** @var list<object> */
        public array $last_result = [];

        /** @var list<string> every statement, in order */
        public array $queries = [];

        /** @var list<object> rows the membership candidate read returns */
        public array $candidateRows = [];

        /** @var list<object> rows the ranked status read returns */
        public array $statusRows = [];

        public bool $failCandidates = false;

        public bool $failStatuses = false;

        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            $next = 0;
            $sql  = preg_replace_callback(
                '/%[ds]/',
                static function (array $m) use (&$next, $args): string {
                    $value = $args[$next++] ?? '<<UNBOUND>>';
                    return $m[0] === '%d' ? (string) (int) $value : "'" . (string) $value . "'";
                },
                $query
            );
            TestCase::assertSame(count($args), $next, 'every bound argument must have a placeholder');
            return trim((string) preg_replace('/\s+/', ' ', (string) $sql));
        }

        public function query(string $sql): int|false
        {
            $this->queries[]   = $sql;
            $this->last_result = [];
            $this->last_error  = '';

            if (str_contains($sql, 'GROUP BY gm.gm_group_id')) {
                if ($this->failCandidates) {
                    $this->last_error = 'simulated failure';
                    return false;
                }
                $this->last_result = $this->candidateRows;
            } elseif (str_contains($sql, 'SELECT gm_group_id, gm_user_status FROM')) {
                if ($this->failStatuses) {
                    $this->last_error = 'simulated failure';
                    return false;
                }
                $this->last_result = $this->statusRows;
            }
            return count($this->last_result);
        }

        /** @return list<object> */
        public function get_results(string $sql): array
        {
            $this->queries[] = $sql;
            return [];
        }

        public function activitySql(): ?string
        {
            foreach ($this->queries as $sql) {
                if (str_contains($sql, 'FROM wp_peepso_activities a')) {
                    return $sql;
                }
            }
            return null;
        }

        public function membershipReads(): int
        {
            return count(array_filter(
                $this->queries,
                static fn (string $sql): bool => str_contains($sql, 'peepso_group_members')
            ));
        }
    }

    /**
     * F-416 "My Groups": the home feed's posts from the groups and Halls the
     * viewer belongs to.
     *
     *   - PeepSoActivityRepository::getActivities($onlyForGroupIds): a MEMBER
     *     read across those groups, decided per post, with no public_all gate;
     *   - $softExcludedAuthorIds / $softExclusionExemptGroupIds: the channel
     *     bcc-trust uses so the shadow-limit lifts where a room lifts it;
     *   - ActivityFeedService's 'groups' scope;
     *   - PeepSoGroupRepository::getActiveMemberGroupIdsOrFail(): published
     *     groups, ranked so a ban wins (F-354), null on a failed read.
     *
     * These assert STATEMENTS and the membership logic. Which rows a real
     * database returns is covered by bcc-trust's integration suite.
     */
    #[CoversClass(ActivityFeedService::class)]
    #[CoversMethod(PeepSoActivityRepository::class, 'getActivities')]
    #[CoversMethod(PeepSoGroupRepository::class, 'getActiveMemberGroupIdsOrFail')]
    final class ActivityMyGroupsScopeTest extends TestCase
    {
        private const ALLOW_7_9 = "EXISTS (SELECT 1 FROM wp_postmeta og_pm WHERE og_pm.post_id = p.ID"
            . " AND og_pm.meta_key = 'peepso_group_id' AND og_pm.meta_value IN ('7','9'))"
            . " AND NOT EXISTS (SELECT 1 FROM wp_postmeta og_neg WHERE og_neg.post_id = p.ID"
            . " AND og_neg.meta_key = 'peepso_group_id' AND og_neg.meta_value NOT IN ('7','9'))";

        private const PUBLIC_ALL_GATE = "(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')";

        /** @var mixed */
        private $priorWpdb;

        private MyGroupsWpdb $wpdb;

        protected function setUp(): void
        {
            require_once dirname(__DIR__) . '/tests/Stubs/nonopen-groups-seed.php';
            \bcc_test_seed_nonopen_groups([]);
            $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
            $this->wpdb      = new MyGroupsWpdb();
            $GLOBALS['wpdb'] = $this->wpdb;
        }

        protected function tearDown(): void
        {
            if ($this->priorWpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $this->priorWpdb;
            }
        }

        private static function service(bool $throwOnReadFailure = false): ActivityFeedService
        {
            $svc = (new \ReflectionClass(ActivityFeedService::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(ActivityFeedService::class, 'throwOnReadFailure'))->setValue($svc, $throwOnReadFailure);
            return $svc;
        }

        private function activitySql(): string
        {
            $sql = $this->wpdb->activitySql();
            self::assertNotNull($sql, 'precondition: the activity read ran');
            return $sql;
        }

        // ── the repository: a member read across the viewer's groups ──────

        /** FAILS IF THE LIST IS IGNORED, OR IF IT IS JUDGED PER ROW. */
        public function testTheAllowListIsAPerPostMemberRead(): void
        {
            // A group that is non-open NOW: the syndication gate would name it.
            \bcc_test_seed_nonopen_groups([55]);

            PeepSoActivityRepository::getActivities(null, null, null, null, 10, onlyForGroupIds: [7, 9, 7, 0]);

            $sql = $this->activitySql();
            self::assertStringContainsString(self::ALLOW_7_9, $sql, 'in one of them and in none outside them');
            self::assertStringNotContainsString(self::PUBLIC_ALL_GATE, $sql, 'members see members_only posts');
            self::assertStringNotContainsString('gx_pm', $sql);
            self::assertStringNotContainsString("'55'", $sql, 'no syndication gate on a member read');
        }

        public function testAViewerInNoGroupReadsNothing(): void
        {
            self::assertSame([], PeepSoActivityRepository::getActivities(null, null, null, null, 10, onlyForGroupIds: []));
            self::assertSame([], PeepSoActivityRepository::getActivities(null, null, null, null, 10, onlyForGroupIds: [0, -3]));
            self::assertSame([], $this->wpdb->queries, 'no query at all');
        }

        /** Refused, never truncated to a subset the caller did not choose. */
        public function testAListOverTheCapReadsNothing(): void
        {
            $ids = range(1, PeepSoActivityRepository::RESTRICTED_GROUP_IDS_MAX + 1);

            self::assertSame([], PeepSoActivityRepository::getActivities(null, null, null, null, 10, onlyForGroupIds: $ids));
            self::assertSame([], $this->wpdb->queries);
        }

        public function testASingleGroupReadIgnoresTheList(): void
        {
            PeepSoActivityRepository::getActivities(null, null, null, null, 10, null, null, 5, onlyForGroupIds: [7, 9]);

            self::assertStringNotContainsString('og_pm', $this->activitySql());
        }

        // ── the soft exclusion ────────────────────────────────────────────

        public function testWithoutAnExemptionTheSoftExclusionIsAPlainNotIn(): void
        {
            PeepSoActivityRepository::getActivities(null, null, null, null, 10, softExcludedAuthorIds: [3, 4, 3]);

            $sql = $this->activitySql();
            self::assertStringContainsString(' AND p.post_author NOT IN (3,4) AND ', $sql);
            self::assertStringNotContainsString('se_pm', $sql);
        }

        /** FAILS IF THE EXEMPTION IS IGNORED, OR IF IT EXEMPTS POSTS OUTSIDE THE ROOM. */
        public function testTheSoftExclusionLiftsOnlyInsideTheExemptGroups(): void
        {
            PeepSoActivityRepository::getActivities(null, null, null, null, 10, softExcludedAuthorIds: [3, 4], softExclusionExemptGroupIds: [9, 9]);

            self::assertStringContainsString(
                "(p.post_author NOT IN (3,4) OR EXISTS (SELECT 1 FROM wp_postmeta se_pm WHERE se_pm.post_id = p.ID"
                    . " AND se_pm.meta_key = 'peepso_group_id' AND se_pm.meta_value IN ('9')))",
                $this->activitySql()
            );
        }

        /** Over the cap: exempt nothing, so the exclusion applies everywhere. */
        public function testAnExemptionListOverTheCapExemptsNothing(): void
        {
            $ids = range(1, PeepSoActivityRepository::RESTRICTED_GROUP_IDS_MAX + 1);

            PeepSoActivityRepository::getActivities(null, null, null, null, 10, softExcludedAuthorIds: [3], softExclusionExemptGroupIds: $ids);

            $sql = $this->activitySql();
            self::assertStringContainsString(' AND p.post_author NOT IN (3) AND ', $sql);
            self::assertStringNotContainsString('se_pm', $sql);
        }

        /** Every value lands on its own placeholder: a misordered param would render a wrong id here. */
        public function testTheParamsBindInClauseOrder(): void
        {
            PeepSoActivityRepository::getActivities(
                null,
                null,
                '2026-11-18 10:00:00',
                900,
                21,
                [11],
                [13],
                onlyForGroupIds: [7, 9],
                softExcludedAuthorIds: [3],
                softExclusionExemptGroupIds: [9]
            );

            self::assertStringContainsString(
                " WHERE p.post_status = 'publish'"
                    . ' AND (a.act_comment_object_id = 0 OR a.act_comment_object_id IS NULL)'
                    . ' AND p.post_author NOT IN (11)'
                    . ' AND a.act_id NOT IN (13)'
                    . " AND (p.post_author NOT IN (3) OR EXISTS (SELECT 1 FROM wp_postmeta se_pm WHERE se_pm.post_id = p.ID"
                    . " AND se_pm.meta_key = 'peepso_group_id' AND se_pm.meta_value IN ('9')))"
                    . ' AND ' . self::ALLOW_7_9
                    . ' AND a.act_module_id IN (1,4,200,201,202,203,204,205)'
                    . " AND (p.post_date_gmt < '2026-11-18 10:00:00'"
                    . " OR (p.post_date_gmt = '2026-11-18 10:00:00' AND a.act_id < 900))"
                    . ' ORDER BY p.post_date_gmt DESC, a.act_id DESC LIMIT 21',
                $this->activitySql()
            );
        }

        // ── the service's 'groups' scope ──────────────────────────────────

        public function testGroupsIsAValidScope(): void
        {
            self::assertContains(ActivityFeedService::SCOPE_GROUPS, ActivityFeedService::VALID_SCOPES);
            self::assertSame('groups', ActivityFeedService::SCOPE_GROUPS);
        }

        public function testAViewerInNoGroupGetsAnEmptyPageAndNoFeedQuery(): void
        {
            $page = self::service()->getFeed(5, 'groups', onlyForGroupIds: []);

            self::assertSame(['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]], $page);
            self::assertNull($this->wpdb->activitySql());
        }

        public function testTheCallersListIsReadAsIs(): void
        {
            self::service()->getFeed(5, 'groups', onlyForGroupIds: [7, 9]);

            self::assertStringContainsString(self::ALLOW_7_9, $this->activitySql());
            self::assertSame(0, $this->wpdb->membershipReads(), 'the caller already resolved it');
        }

        /** Without a list the service reads the viewer's ACTIVE groups: a ban beside a member row wins. */
        public function testWithoutAListTheServiceReadsTheViewersActiveGroups(): void
        {
            $this->wpdb->candidateRows = [
                (object) ['gm_group_id' => '7'],
                (object) ['gm_group_id' => '8'],
                (object) ['gm_group_id' => '9'],
            ];
            $this->wpdb->statusRows = [
                (object) ['gm_group_id' => '7', 'gm_user_status' => 'member'],
                (object) ['gm_group_id' => '8', 'gm_user_status' => 'member'],
                (object) ['gm_group_id' => '8', 'gm_user_status' => 'banned'],
                (object) ['gm_group_id' => '9', 'gm_user_status' => 'member_owner'],
            ];

            self::service()->getFeed(5, 'groups');

            self::assertStringContainsString(self::ALLOW_7_9, $this->activitySql());
        }

        public function testOtherScopesIgnoreTheList(): void
        {
            self::service()->getFeed(5, 'for_you', onlyForGroupIds: [7, 9]);

            $sql = $this->activitySql();
            self::assertStringNotContainsString('og_pm', $sql);
            self::assertStringContainsString(self::PUBLIC_ALL_GATE, $sql, 'the mixed feed keeps its gate');
        }

        /** "Try again", not "you are in no group". */
        public function testAFailedMembershipReadThrowsWhenTheServiceWasAskedTo(): void
        {
            $this->wpdb->failCandidates = true;

            try {
                self::service(true)->getFeed(5, 'groups');
                self::fail('a failed membership read must not read as "a member of nothing"');
            } catch (ActivityReadFailedException) {
            }
            self::assertNull($this->wpdb->activitySql());

            $this->wpdb->queries = [];
            self::assertSame([], self::service(false)->getFeed(5, 'groups')['items'], 'the historical empty answer otherwise');
        }

        // ── the membership read ───────────────────────────────────────────

        public function testTheCandidateReadIsPublishedActiveGroupsNewestFirstAndBounded(): void
        {
            PeepSoGroupRepository::getActiveMemberGroupIdsOrFail(5, 500);

            self::assertCount(1, $this->wpdb->queries, 'no candidates, so no ranking read');
            self::assertSame(
                'SELECT gm.gm_group_id FROM wp_peepso_group_members gm'
                    . ' INNER JOIN wp_posts p ON p.ID = gm.gm_group_id'
                    . " WHERE gm.gm_user_id = 5 AND gm.gm_user_status LIKE 'member%'"
                    . " AND p.post_type = 'peepso-group' AND p.post_status = 'publish'"
                    . ' GROUP BY gm.gm_group_id ORDER BY MAX(gm.gm_joined) DESC, gm.gm_group_id DESC LIMIT 200',
                $this->wpdb->queries[0]
            );
        }

        /** F-354: a ban wins over a stale member row; owner and member rows are active; a pending duplicate loses to membership. */
        public function testOnlyGroupsWhoseRankedStatusIsActiveAreKept(): void
        {
            $this->wpdb->candidateRows = [
                (object) ['gm_group_id' => '9'],
                (object) ['gm_group_id' => '8'],
                (object) ['gm_group_id' => '7'],
                (object) ['gm_group_id' => '6'],
            ];
            $this->wpdb->statusRows = [
                (object) ['gm_group_id' => '9', 'gm_user_status' => 'member_owner'],
                (object) ['gm_group_id' => '8', 'gm_user_status' => 'member'],
                (object) ['gm_group_id' => '8', 'gm_user_status' => 'banned'],
                (object) ['gm_group_id' => '7', 'gm_user_status' => 'pending_admin'],
                (object) ['gm_group_id' => '7', 'gm_user_status' => 'member_moderator'],
                (object) ['gm_group_id' => '6', 'gm_user_status' => 'pending_user'],
            ];

            self::assertSame([9, 7], PeepSoGroupRepository::getActiveMemberGroupIdsOrFail(5));
        }

        public function testEitherReadFailingIsNullNeverEmpty(): void
        {
            $this->wpdb->failCandidates = true;
            self::assertNull(PeepSoGroupRepository::getActiveMemberGroupIdsOrFail(5));

            $this->wpdb->failCandidates = false;
            $this->wpdb->candidateRows  = [(object) ['gm_group_id' => '7']];
            $this->wpdb->failStatuses   = true;
            self::assertNull(PeepSoGroupRepository::getActiveMemberGroupIdsOrFail(5));
        }

        public function testNoViewerIsAMemberOfNothingWithoutAQuery(): void
        {
            self::assertSame([], PeepSoGroupRepository::getActiveMemberGroupIdsOrFail(0));
            self::assertSame([], $this->wpdb->queries);
        }
    }
}
