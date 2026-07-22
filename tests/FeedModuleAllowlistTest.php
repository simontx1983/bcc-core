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
        /** The exact numeric modules that may surface publicly. */
        private const ALLOWED = [1, 4, 200, 201, 202, 203, 204];

        /** Non-post / unsupported modules that must be excluded. */
        private const EXCLUDED = [0, 6, 8, 9, 30, 111, 6661];

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
                'a.act_module_id IN (%d,%d,%d,%d,%d,%d,%d)',
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

            self::assertStringContainsString('a.act_module_id IN (%d,%d,%d,%d,%d,%d,%d)', $wpdb->lastSql());
            foreach (self::ALLOWED as $id) {
                self::assertContains($id, $wpdb->lastArgs());
            }
        }

        public function testExplicitModuleListIsHonoredVerbatimAndUnchanged(): void
        {
            $wpdb = $this->installWpdb();

            // Signals scope passes ['signal']; the blog tab passes ['blog'].
            PeepSoActivityRepository::getActivities([5], ['signal'], null, null, 10);

            self::assertStringContainsString('a.act_module_id IN (%s)', $wpdb->lastSql());
            self::assertContains('signal', $wpdb->lastArgs());
            // The numeric allowlist must NOT be injected onto an explicit list.
            foreach (self::ALLOWED as $id) {
                self::assertNotContains($id, $wpdb->lastArgs());
            }
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

            self::assertStringContainsString('a.act_module_id IN (%d,%d,%d,%d,%d,%d,%d)', $wpdb->lastSql());
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
                self::assertStringContainsString('a.act_module_id IN (%d,%d,%d,%d,%d,%d,%d)', $wpdb->lastSql());
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
