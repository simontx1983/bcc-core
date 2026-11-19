<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\ModuleAllowlist {

    use BCC\Core\Feed\ActivityFeedService;
    use BCC\Core\Feed\FeedItemNormalizer;
    use BCC\Core\Repositories\PeepSoActivityRepository;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;

    /**
     * Minimal capturing `$wpdb` double. Records the placeholder SQL and the
     * bound params each `prepare()` receives so tests can assert the module
     * allowlist is enforced at the CANDIDATE QUERY (the SQL WHERE), not
     * merely after retrieval. `get_results()` / `get_row()` return preset
     * fixtures — the double never runs SQL, so the row it hands back
     * simulates whatever a real DB might return (used to prove the service
     * seam fails closed independently of the query).
     */
    final class FeedCaptureWpdb
    {
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $prefix   = 'wp_';

        /** @var list<array{sql: string, args: list<mixed>}> */
        public array $prepared = [];

        /** @var list<object> */
        public array $resultsToReturn = [];

        /** @var object|null */
        public $rowToReturn = null;

        /**
         * @param mixed ...$args
         */
        public function prepare(string $query, ...$args): string
        {
            $this->prepared[] = ['sql' => $query, 'args' => array_values($args)];
            return $query;
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        /**
         * @param string $sql
         * @return list<object>
         */
        public function get_results($sql): array
        {
            return $this->resultsToReturn;
        }

        /**
         * @param string $sql
         * @return object|null
         */
        public function get_row($sql)
        {
            return $this->rowToReturn;
        }

        /** The single most-recent captured prepare() call. */
        public function lastSql(): string
        {
            $last = $this->prepared[count($this->prepared) - 1] ?? null;
            return $last['sql'] ?? '';
        }

        /** @return list<mixed> */
        public function lastArgs(): array
        {
            $last = $this->prepared[count($this->prepared) - 1] ?? null;
            return $last['args'] ?? [];
        }
    }

    /**
     * F058 — private DMs and unsupported PeepSo activity modules must never
     * become public feed items or be resolvable through the public
     * single-item permalink. Proves the canonical allowlist
     * (FeedItemNormalizer::publicFeedModuleIds) is enforced at both
     * authoritative backend seams: the getActivities() candidate query and
     * the getActivityById() permalink path.
     */
    #[CoversClass(FeedItemNormalizer::class)]
    #[CoversClass(PeepSoActivityRepository::class)]
    #[CoversClass(ActivityFeedService::class)]
    final class FeedModuleAllowlistTest extends TestCase
    {
        /**
         * The exact numeric modules that may surface publicly.
         *
         * 205 (`announcement`) is admitted ahead of its writer: no code
         * emits module 205 until bcc-trust's A3-trust ships, so admitting
         * it here cannot surface a row. Listing it is what proves the
         * normalization landed — this assertion is `assertSame` on the
         * sorted set, so the entry cannot be added to the map without
         * being accounted for here.
         */
        private const ALLOWED = [1, 4, 200, 201, 202, 203, 204, 205];

        /** Non-post / unsupported modules that must be excluded. */
        private const EXCLUDED = [0, 6, 8, 9, 30, 111, 6661];

        /**
         * The prepared-statement fragment the allowlist must produce.
         *
         * ⚠ The placeholder count is a LITERAL on purpose, and was four
         * duplicated literals until module 205 made all four fail at once.
         * Deriving it from `count(self::ALLOWED)` would make these
         * assertions vacuous — they would then match whatever the
         * production code emitted, including an allowlist that had
         * silently grown. One literal in one place keeps the assertion
         * meaningful while making the next addition a one-line update
         * rather than a four-line hunt.
         */
        private const MODULE_IN_PLACEHOLDERS = 'a.act_module_id IN (%d,%d,%d,%d,%d,%d,%d,%d)';

        /**
         * The comment-excluding predicate, spelled ONCE here and asserted
         * against both readers. ⚠ It is NULL-safe on purpose: a non-comment
         * row may store NULL rather than 0, and `= 0` alone would drop it.
         */
        private const TOP_LEVEL_PREDICATE = '(a.act_comment_object_id = 0 OR a.act_comment_object_id IS NULL)';

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

        // ── The canonical allowlist ─────────────────────────────────────

        public function testPublicFeedModuleIdsIsExactlyTheNumericMap(): void
        {
            $ids = FeedItemNormalizer::publicFeedModuleIds();
            sort($ids);
            $expected = self::ALLOWED;
            sort($expected);
            self::assertSame($expected, $ids);
        }

        public function testAllowlistDerivesFromModuleToKindAndExcludesStringKeys(): void
        {
            $ids = FeedItemNormalizer::publicFeedModuleIds();

            // Every allowed id is a real post_kind in the map — no drift.
            foreach ($ids as $id) {
                self::assertArrayHasKey($id, FeedItemNormalizer::MODULE_TO_KIND);
                self::assertIsInt($id);
            }
            // Legacy string keys ('status', 'signal', 'blog', …) are NOT ids.
            foreach (['status', 'review', 'signal', 'blog', 'nft', 'project', 'pull_batch'] as $stringKey) {
                self::assertNotContains($stringKey, $ids);
            }
            // Known non-post modules never appear.
            foreach (self::EXCLUDED as $bad) {
                self::assertNotContains($bad, $ids, "module {$bad} must not be public");
            }
        }

        // ── getActivities() candidate-query enforcement ─────────────────

        public function testGlobalFeedNullModulesFailsClosedToAllowlist(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities(null, null, null, null, 10);

            self::assertStringContainsString(
                self::MODULE_IN_PLACEHOLDERS,
                $wpdb->lastSql(),
                'null $moduleIds must narrow to the numeric allowlist, not "all modules"'
            );
            foreach (self::ALLOWED as $id) {
                self::assertContains($id, $wpdb->lastArgs());
            }
            foreach (self::EXCLUDED as $bad) {
                self::assertNotContains($bad, $wpdb->lastArgs());
            }
        }

        public function testGroupScopedNullModulesAlsoFailsClosedToAllowlist(): void
        {
            $wpdb = $this->installWpdb();

            // onlyForGroupId set → group-scoped path still gets the allowlist.
            PeepSoActivityRepository::getActivities([5], null, null, null, 10, null, null, 42);

            self::assertStringContainsString(self::MODULE_IN_PLACEHOLDERS, $wpdb->lastSql());
            foreach (self::ALLOWED as $id) {
                self::assertContains($id, $wpdb->lastArgs());
            }
        }

        /**
         * F-392: the blog tab passes the NAME 'blog'. It used to be bound as
         * the string 'blog', which MySQL casts to 0 against the SMALLINT
         * column, so the tab matched legacy module-0 rows and never a blog
         * (204). It is now bound as 204, and only 204.
         */
        public function testAModuleNameIsBoundAsItsNumericId(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities([5], ['blog'], null, null, 10);

            self::assertStringContainsString('a.act_module_id IN (%d)', $wpdb->lastSql());
            self::assertStringNotContainsString('a.act_module_id IN (%s)', $wpdb->lastSql());
            self::assertContains(204, $wpdb->lastArgs());
            self::assertNotContains('blog', $wpdb->lastArgs());
            self::assertNotContains(0, $wpdb->lastArgs(), 'nothing that becomes module 0');
            // The numeric allowlist must NOT be injected onto an explicit list.
            foreach (array_diff(self::ALLOWED, [204]) as $id) {
                self::assertNotContains($id, $wpdb->lastArgs());
            }
        }

        /**
         * F-392: the signals scope passes the NAME 'signal'. No writer emits a
         * numeric signal module, so it resolves to nothing: no rows and no
         * query, rather than the legacy module-0 rows `IN ('signal')` matched.
         */
        public function testTheSignalNameResolvesToNothingAndIssuesNoQuery(): void
        {
            $wpdb = $this->installWpdb();
            $wpdb->resultsToReturn = [(object) ['act_id' => 1, 'act_module_id' => '0']];

            self::assertSame([], PeepSoActivityRepository::getActivities([5], ['signal'], null, null, 10));
            self::assertSame([], $wpdb->prepared, 'no statement is prepared');
        }

        public function testAnExplicitNumericListIsBoundAsIntegers(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getActivities([5], [204, '202'], null, null, 10);

            self::assertStringContainsString('a.act_module_id IN (%d,%d)', $wpdb->lastSql());
            self::assertContains(204, $wpdb->lastArgs());
            self::assertContains(202, $wpdb->lastArgs());
            self::assertNotContains('202', $wpdb->lastArgs());
        }

        /** Zero, unknown names and junk are dropped; a list left empty returns nothing, never everything. */
        public function testZeroAndUnknownEntriesAreDropped(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoActivityRepository::getActivities([5], [0, 'no-such-module', 204], null, null, 10);
            self::assertStringContainsString('a.act_module_id IN (%d)', $wpdb->lastSql());
            self::assertNotContains(0, $wpdb->lastArgs());

            $wpdb = $this->installWpdb();
            self::assertSame([], PeepSoActivityRepository::getActivities([5], ['0', 'no-such-module', ''], null, null, 10));
            self::assertSame([], $wpdb->prepared, 'an all-junk list falls back to NO rows, not the default gate');
        }

        /** The scope the Signals tab calls: an empty page, no query. */
        public function testTheSignalsScopeIsAnEmptyPageWithNoQuery(): void
        {
            $wpdb = $this->installWpdb();

            $page = self::service()->getFeed(0, ActivityFeedService::SCOPE_SIGNALS);

            self::assertSame([], $page['items']);
            self::assertFalse($page['pagination']['has_more']);
            self::assertNull($page['pagination']['next_cursor']);
            self::assertSame([], $wpdb->prepared);
        }

        /**
         * moduleIdsFor() maps a name to every numeric id of the same KIND,
         * through MODULE_TO_KIND's string keys, so it cannot drift from the
         * map: 'pull_batch' (legacy alias) and 'watch_batch' are both 200.
         */
        public function testModuleIdsForResolvesNamesThroughTheMap(): void
        {
            self::assertSame([204], FeedItemNormalizer::moduleIdsFor(['blog']));
            self::assertSame([202], FeedItemNormalizer::moduleIdsFor(['review']));
            self::assertSame([200], FeedItemNormalizer::moduleIdsFor(['watch_batch']));
            self::assertSame([200], FeedItemNormalizer::moduleIdsFor(['pull_batch']));
            self::assertSame([201], FeedItemNormalizer::moduleIdsFor(['page_claim']));
            self::assertSame([203], FeedItemNormalizer::moduleIdsFor(['dispute']));
            self::assertSame([1], FeedItemNormalizer::moduleIdsFor(['status']));
            self::assertSame([], FeedItemNormalizer::moduleIdsFor(['signal']), 'no numeric signal module exists');
            self::assertSame([], FeedItemNormalizer::moduleIdsFor(['project', 'nft']), 'kinds with no numeric module');
            self::assertSame([], FeedItemNormalizer::moduleIdsFor(['no-such-module', '', '-5', -5, 0, '0']));
        }

        public function testModuleIdsForKeepsNumbersDeduplicatesAndKeepsOrder(): void
        {
            self::assertSame([204, 202], FeedItemNormalizer::moduleIdsFor(['204', 202, 'blog', '204']));
            self::assertSame([30], FeedItemNormalizer::moduleIdsFor([30]), 'a number is kept as given; the wall narrows it to public modules');
            self::assertSame([30], FeedItemNormalizer::moduleIdsFor(['30']), 'digits are a number too, mapped or not');
        }

        public function testEmptyModuleListShortCircuitsToNoRows(): void
        {
            $this->installWpdb();
            self::assertSame([], PeepSoActivityRepository::getActivities(null, [], null, null, 10));
        }

        public function testAllowedRowsFlowThroughUnfiltered(): void
        {
            $wpdb = $this->installWpdb();
            $wpdb->resultsToReturn = [
                (object) ['act_id' => 1, 'act_module_id' => '1'],
                (object) ['act_id' => 2, 'act_module_id' => '204'],
            ];
            $rows = PeepSoActivityRepository::getActivities(null, null, null, null, 10);
            self::assertCount(2, $rows);
        }

        // ── getById() permalink candidate-query enforcement ─────────────

        public function testGetByIdWithAllowlistAddsModuleClause(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getById(7, FeedItemNormalizer::publicFeedModuleIds());

            self::assertStringContainsString(self::MODULE_IN_PLACEHOLDERS, $wpdb->lastSql());
            self::assertContains(7, $wpdb->lastArgs());
            foreach (self::ALLOWED as $id) {
                self::assertContains($id, $wpdb->lastArgs());
            }
        }

        public function testGetByIdWithoutAllowlistIsUnfilteredForInternalCallers(): void
        {
            $wpdb = $this->installWpdb();

            // Author resolution / moderation / comment-parent lookups pass no
            // allowlist and must still resolve any activity kind.
            PeepSoActivityRepository::getById(7);

            self::assertStringNotContainsString('act_module_id IN', $wpdb->lastSql());
        }

        public function testGetByIdWithEmptyAllowlistReturnsNull(): void
        {
            $this->installWpdb();
            self::assertNull(PeepSoActivityRepository::getById(7, []));
        }

        // ── COMMENT rows are not feed items (the act-id permalink leak) ──

        /**
         * ⚠⚠ THE LEAK THIS CLOSES, stated plainly because the module
         * allowlist looks like it should already cover it and does not.
         *
         * PeepSo writes an activities row for EVERY comment and stores
         * `act_module_id = PeepSoActivity::MODULE_ID` (1) on it — its own
         * source says "comments always belong to the activity module". So a
         * comment act id satisfies the allowlist above, its backing wp_post
         * is published, and it resolves here as though it were a top-level
         * post.
         *
         * ⛔ And a comment post carries NO `peepso_group_id` — PeepSo's own
         * groups add-on walks UP to the parent to find the group — so every
         * group rule keyed on the resolved post reads "not group-tagged"
         * for a comment and admits it. The net effect was that comment act
         * ids were readable by link regardless of the group they live in,
         * and act ids are sequential, so they can be walked.
         *
         * `getActivities()` has carried the excluding predicate all along;
         * this is the single-row reader catching up to it.
         */
        public function testGetByIdWithAllowlistExcludesCommentRows(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getById(7, FeedItemNormalizer::publicFeedModuleIds());

            self::assertStringContainsString(
                self::TOP_LEVEL_PREDICATE,
                $wpdb->lastSql(),
                'a feed-shaped getById must exclude comment rows in SQL'
            );
        }

        /**
         * ⭐ The drift guard, and the reason this predicate is not re-worded
         * here: the single-row reader and the candidate query must carry the
         * IDENTICAL fragment. Two spellings of the same rule is how one of
         * them later loses a leg.
         */
        public function testTheTopLevelPredicateIsIdenticalInBothReaders(): void
        {
            $wpdb = $this->installWpdb();
            PeepSoActivityRepository::getActivities(null, null, null, null, 10);
            $candidateSql = $wpdb->lastSql();

            $wpdb = $this->installWpdb();
            PeepSoActivityRepository::getById(7, FeedItemNormalizer::publicFeedModuleIds());
            $byIdSql = $wpdb->lastSql();

            self::assertStringContainsString(self::TOP_LEVEL_PREDICATE, $candidateSql);
            self::assertStringContainsString(self::TOP_LEVEL_PREDICATE, $byIdSql);
        }

        /**
         * The internal callers are deliberately unchanged. Author
         * resolution, the comment-PARENT lookup, moderation and the
         * interaction gate's comment branch all pass no allowlist and must
         * still resolve a comment act — `GroupInteractionGate` resolves a
         * comment to its parent BEFORE it ever reaches a feed-shaped read,
         * and breaking that would break comment moderation rather than
         * protect anything.
         */
        public function testGetByIdWithoutAllowlistStillResolvesCommentRows(): void
        {
            $wpdb = $this->installWpdb();

            PeepSoActivityRepository::getById(7);

            self::assertStringNotContainsString(
                'act_comment_object_id',
                $wpdb->lastSql(),
                'internal callers must keep resolving comment acts'
            );
        }

        /** The flag overrides the default in BOTH directions. */
        public function testTopLevelOnlyCanBeForcedOnAndOff(): void
        {
            $wpdb = $this->installWpdb();
            // Forced ON with no allowlist.
            PeepSoActivityRepository::getById(7, null, null, false, 0, false, true);
            self::assertStringContainsString(self::TOP_LEVEL_PREDICATE, $wpdb->lastSql());

            $wpdb = $this->installWpdb();
            // Forced OFF despite an allowlist.
            PeepSoActivityRepository::getById(7, FeedItemNormalizer::publicFeedModuleIds(), null, false, 0, false, false);
            self::assertStringNotContainsString('act_comment_object_id', $wpdb->lastSql());
        }

        // ── getActivityById() permalink rejection ───────────────────────

        /**
         * The security assertion: a DM / poll / background / group-notice /
         * unknown-module activity must not be retrievable via the public
         * permalink even when its backing post is published. Also proves the
         * permalink enforces at the candidate query (getById receives the
         * allowlist) — belt AND suspenders.
         */
        public function testExcludedModulesAreNotRetrievableViaPermalink(): void
        {
            foreach (self::EXCLUDED as $badModule) {
                $wpdb = $this->installWpdb();
                $wpdb->rowToReturn = (object) [
                    'act_id'          => 150,
                    'act_user_id'     => 140,
                    'act_owner_id'    => 140,
                    'act_module_id'   => (string) $badModule,
                    'act_external_id' => 4973,
                    'act_time'        => '2026-06-11 20:14:18',
                    'act_access'      => '0',
                    'act_status'      => 'publish',
                ];

                $result = self::service()->getActivityById(150, 0);

                self::assertNull($result, "module {$badModule} must not resolve via /feed/{id}");
                // The permalink enforced the allowlist in SQL, not just after.
                self::assertStringContainsString(self::MODULE_IN_PLACEHOLDERS, $wpdb->lastSql());
            }
        }

        public function testNonPublishedRowIsRejectedRegardlessOfModule(): void
        {
            $wpdb = $this->installWpdb();
            $wpdb->rowToReturn = (object) [
                'act_id'          => 150,
                'act_user_id'     => 140,
                'act_module_id'   => '1', // allowed module …
                'act_external_id' => 4973,
                'act_time'        => '2026-06-11 20:14:18',
                'act_access'      => '0',
                'act_status'      => 'draft', // … but not published
            ];
            self::assertNull(self::service()->getActivityById(150, 0));
        }

        // ── $deferGroupVisibilityToCaller rescues NOTHING else ──────
        //
        // The parameter defers ONE question: whether a group-tagged post
        // may be resolved by id. These assert that it does not quietly
        // become a skeleton key for the gates ABOVE it in the method —
        // all of which return before the group branch is ever reached.
        //
        // ⚠ Worth pinning explicitly rather than reasoning from source
        // order: the risk with an opt-in like this is not that it fails,
        // it is that a later refactor moves it earlier.

        /** A draft stays unreachable even when the caller takes the group decision. */
        public function testDeferringDoesNotRescueANonPublishedRow(): void
        {
            $wpdb = $this->installWpdb();
            $wpdb->rowToReturn = (object) [
                'act_id'          => 150,
                'act_user_id'     => 140,
                'act_module_id'   => '1',
                'act_external_id' => 4973,
                'act_time'        => '2026-06-11 20:14:18',
                'act_access'      => '0',
                'act_status'      => 'draft',
            ];

            self::assertNull(self::service()->getActivityById(
                150,
                0,
                deferGroupVisibilityToCaller: true
            ));
        }

        /** A private-module activity stays unreachable too (F058 holds). */
        public function testDeferringDoesNotRescueAnExcludedModule(): void
        {
            $wpdb = $this->installWpdb();
            $wpdb->rowToReturn = (object) [
                'act_id'          => 150,
                'act_user_id'     => 140,
                'act_module_id'   => '6', // private message
                'act_external_id' => 4973,
                'act_time'        => '2026-06-11 20:14:18',
                'act_access'      => '0',
                'act_status'      => 'publish',
            ];

            self::assertNull(self::service()->getActivityById(
                150,
                0,
                deferGroupVisibilityToCaller: true
            ));
        }

        /**
         * The opaque exclusion channels still apply: a moderation-hidden
         * act id and a blocked / shadow-limited author are both refused
         * with deferring on.
         */
        public function testDeferringDoesNotRescueAnExcludedAuthorOrActId(): void
        {
            $row = (object) [
                'act_id'          => 150,
                'act_user_id'     => 140,
                'act_module_id'   => '1',
                'act_external_id' => 4973,
                'act_time'        => '2026-06-11 20:14:18',
                'act_access'      => '0',
                'act_status'      => 'publish',
            ];

            $wpdb = $this->installWpdb();
            $wpdb->rowToReturn = $row;
            self::assertNull(
                self::service()->getActivityById(
                    150,
                    0,
                    excludedAuthorIds: [140],
                    deferGroupVisibilityToCaller: true
                ),
                'an excluded author must stay excluded'
            );

            $wpdb = $this->installWpdb();
            $wpdb->rowToReturn = $row;
            self::assertNull(
                self::service()->getActivityById(
                    150,
                    0,
                    excludedActIds: [150],
                    deferGroupVisibilityToCaller: true
                ),
                'a hidden act id must stay hidden'
            );
        }

        // ── isPublicFeedModule() gate truth table ───────────────────────

        public function testIsPublicFeedModuleTruthTable(): void
        {
            foreach (self::ALLOWED as $id) {
                self::assertTrue(self::isPublicFeedModule((string) $id), "module {$id} should be public");
            }
            foreach (self::EXCLUDED as $id) {
                self::assertFalse(self::isPublicFeedModule((string) $id), "module {$id} should be denied");
            }
            // Non-numeric / absent module ids fail closed.
            self::assertFalse(self::isPublicFeedModule(''));
            self::assertFalse(self::isPublicFeedModule('signal'));
            self::assertFalse(self::isPublicFeedModule('status'));
            self::assertFalse(self::isPublicFeedModule('1abc'));
        }

        // ── helpers ─────────────────────────────────────────────────────

        private function installWpdb(): FeedCaptureWpdb
        {
            $wpdb = new FeedCaptureWpdb();
            $GLOBALS['wpdb'] = $wpdb;
            return $wpdb;
        }

        private static function service(): ActivityFeedService
        {
            /** @var ActivityFeedService */
            return (new ReflectionClass(ActivityFeedService::class))->newInstanceWithoutConstructor();
        }

        private static function isPublicFeedModule(string $module): bool
        {
            $ref = new ReflectionMethod(ActivityFeedService::class, 'isPublicFeedModule');
            $ref->setAccessible(true);
            $out = $ref->invoke(null, $module);
            self::assertIsBool($out);
            return $out;
        }
    }
}
