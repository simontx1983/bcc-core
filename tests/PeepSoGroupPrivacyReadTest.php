<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\GroupPrivacyRead {

    use BCC\Core\Repositories\PeepSoGroupPrivacy;
    use BCC\Core\Repositories\PeepSoGroupRepository;
    use PDO;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;

    /**
     * `$wpdb` double that models the three outcomes of a real
     * `wpdb::query()` that matter to a probed read:
     *
     *   - 'ok':       rows in `last_result`, query() returns the row count;
     *   - 'error':    `last_error` set, `last_result` flushed, query() false;
     *   - 'preflush': query() returns false BEFORE flush(): no `last_error`,
     *                 and `last_result` still holds the PREVIOUS query's rows
     *                 (wpdb's `! $this->ready` guard, or a 'query' filter
     *                 emptying the SQL).
     *
     * `get_results()` / `get_col()` behave like real wpdb: they call query()
     * and DISCARD its return value, then hand back `last_result`. So a read
     * that used them would see the stale rows in 'preflush' mode, and that
     * is the regression this file exists to catch.
     */
    final class ScriptedWpdb
    {
        public string $postmeta = 'wp_postmeta';
        public string $posts    = 'wp_posts';
        public string $last_error = '';
        /** @var list<object> */
        public array $last_result = [];
        public string $mode = 'ok';
        /** @var list<object> */
        public array $rows = [];
        /** @var list<string> */
        public array $queries = [];

        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            $next = 0;

            return (string) preg_replace_callback(
                '/%[ds]/',
                static function (array $m) use (&$next, $args): string {
                    $value = $args[$next++] ?? '';

                    return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
                },
                $query
            );
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $this->queries[] = $sql;
            if ($this->mode === 'preflush') {
                return false; // before flush(): last_error and last_result untouched
            }
            $this->last_error  = '';
            $this->last_result = [];
            if ($this->mode === 'error') {
                $this->last_error = 'Deadlock found when trying to get lock';

                return false;
            }
            $this->last_result = $this->rows;

            return count($this->rows);
        }

        /** @return list<object> */
        public function get_results(string $sql): array
        {
            $this->query($sql);

            return $this->last_result;
        }

        /** @return list<string> */
        public function get_col(string $sql): array
        {
            $this->query($sql);

            return array_map(static fn (object $r): string => (string) array_values(get_object_vars($r))[0], $this->last_result);
        }
    }

    /**
     * F-460: the group privacy read every BCC PHP caller uses.
     *
     * Two layers:
     *   - the PROBE contract, with a scripted `$wpdb`: a read that cannot be
     *     trusted is null, never "open";
     *   - the SQL, EXECUTED against SQLite (always) and MySQL (opt-in via
     *     BCC_TEST_DB_PORT), over the cases the rule exists for: two-row
     *     groups, stray values, no row. It is also checked against
     *     `notSecretSql()` on the same data, so the single-group answer
     *     cannot disagree with the list predicate, and against BOTH deny
     *     lists (`getNonOpenGroupIds` / `…Strict`, through `nonOpenSql()`),
     *     so a group is denied exactly when the single read says 1 or 2.
     */
    #[CoversClass(PeepSoGroupPrivacy::class)]
    final class PeepSoGroupPrivacyReadTest extends TestCase
    {
        private const PREFIX = 'bcc_f460_';

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

        private function scripted(string $mode, array $rows = []): ScriptedWpdb
        {
            $wpdb       = new ScriptedWpdb();
            $wpdb->mode = $mode;
            $wpdb->rows = $rows;
            $GLOBALS['wpdb'] = $wpdb;

            return $wpdb;
        }

        // ── the probe contract ─────────────────────────────────────────

        public function testATrustedReadMapsEachGroupAndAMissingRowIsOpen(): void
        {
            $wpdb = $this->scripted('ok', [
                (object) ['post_id' => '40', 'strictest' => '2'],
                (object) ['post_id' => '41', 'strictest' => '1'],
            ]);

            self::assertSame(
                [40 => 2, 41 => 1, 42 => 0],
                PeepSoGroupPrivacy::privacyForGroups([40, 41, 42])
            );
            self::assertCount(1, $wpdb->queries, 'one query for the whole batch');
            self::assertSame(2, PeepSoGroupPrivacy::privacyForGroup(40));
        }

        public function testAReportedDriverErrorIsUnknownNotOpen(): void
        {
            $this->scripted('error');

            self::assertNull(PeepSoGroupPrivacy::privacyForGroup(40));
            self::assertNull(PeepSoGroupPrivacy::privacyForGroups([40, 41]));
        }

        /**
         * ⚠⚠ THE PRE-FLUSH BAIL (security, F-460). query() returns false
         * with NO `last_error`, and `last_result` still holds someone else's
         * rows, here an "open" answer. `get_results()`/`get_col()` would
         * read those and answer a confident OPEN for a group that may be
         * secret. Only `query() === false` sees it.
         */
        public function testAPreFlushBailWithStaleRowsIsUnknownNotTheStaleAnswer(): void
        {
            $wpdb = $this->scripted('preflush');
            $wpdb->last_result = [(object) ['post_id' => '40', 'strictest' => '0']];

            self::assertNull(PeepSoGroupPrivacy::privacyForGroup(40), 'stale "open" rows must not be trusted');
            self::assertNull(PeepSoGroupPrivacy::privacyForGroups([40]));
        }

        /**
         * A genuine empty result is NOT a failure. query() returns 0, and
         * `!0` is true, so a falsy test would turn every row-less group into
         * "unknown". Rows-less groups are open (0).
         */
        public function testAGenuinelyEmptyResultIsOpenNotUnknown(): void
        {
            $this->scripted('ok', []);

            self::assertSame(0, PeepSoGroupPrivacy::privacyForGroup(40));
            self::assertSame([40 => 0], PeepSoGroupPrivacy::privacyForGroups([40]));
        }

        /** An earlier, unrelated failure must not poison this read. */
        public function testAnEarlierErrorInTheRequestDoesNotPoisonThisRead(): void
        {
            $wpdb = $this->scripted('ok', [(object) ['post_id' => '40', 'strictest' => '2']]);
            $wpdb->last_error = 'an earlier query failed';

            self::assertSame(2, PeepSoGroupPrivacy::privacyForGroup(40));
        }

        public function testInvalidIdsAreUnknownAndAskNothing(): void
        {
            $wpdb = $this->scripted('ok');

            self::assertNull(PeepSoGroupPrivacy::privacyForGroup(0));
            self::assertNull(PeepSoGroupPrivacy::privacyForGroup(-3));
            self::assertSame([], PeepSoGroupPrivacy::privacyForGroups([]));
            self::assertSame([], PeepSoGroupPrivacy::privacyForGroups([0, -1]));
            self::assertSame([], $wpdb->queries);
        }

        /** Over the cap is REFUSED, never truncated, and asks nothing. */
        public function testOverTheBatchCapIsRefusedNotTruncated(): void
        {
            $wpdb = $this->scripted('ok');

            self::assertNull(PeepSoGroupPrivacy::privacyForGroups(range(1, PeepSoGroupPrivacy::BATCH_MAX + 1)));
            self::assertSame([], $wpdb->queries);

            self::assertIsArray(PeepSoGroupPrivacy::privacyForGroups(range(1, PeepSoGroupPrivacy::BATCH_MAX)));
        }

        public function testIdsAreDeduplicatedAndBoundAsIntegers(): void
        {
            $wpdb = $this->scripted('ok');
            PeepSoGroupPrivacy::privacyForGroups([40, '41', 40, 0]);

            self::assertCount(1, $wpdb->queries);
            self::assertStringContainsString('post_id IN (40,41)', $wpdb->queries[0]);
            self::assertStringContainsString("meta_key = 'peepso_group_privacy'", $wpdb->queries[0]);
        }

        /** A row for an id that was not asked about is ignored. */
        public function testARowForAnUnaskedGroupIsIgnored(): void
        {
            $this->scripted('ok', [(object) ['post_id' => '99', 'strictest' => '2']]);

            self::assertSame([40 => 0], PeepSoGroupPrivacy::privacyForGroups([40]));
        }

        // ── F-518: the open-groups listing, probe contract ─────────────

        public function testTheOpenListingMapsEachRowAndTheHallFlag(): void
        {
            $wpdb = $this->scripted('ok', [
                (object) ['id' => '41', 'slug' => 'g41', 'is_hall' => '0'],
                (object) ['id' => '70', 'slug' => 'h70', 'is_hall' => '1'],
            ]);

            self::assertSame(
                [
                    ['id' => 41, 'slug' => 'g41', 'is_hall' => false],
                    ['id' => 70, 'slug' => 'h70', 'is_hall' => true],
                ],
                PeepSoGroupRepository::openGroupsAfter(5, 2)
            );
            self::assertCount(1, $wpdb->queries);
            $sql = (string) preg_replace('/\s+/', ' ', $wpdb->queries[0]);
            self::assertStringContainsString('p.ID > 5', $sql, 'keyset on the id');
            self::assertStringContainsString('ORDER BY p.ID ASC LIMIT 2', $sql);
            self::assertStringContainsString(PeepSoGroupPrivacy::openSql('p.ID'), $sql, 'the shared open rule');
            self::assertStringContainsString("kind.meta_key = '_bcc_group_kind' AND kind.meta_value = 'hall'", $sql);
            self::assertStringContainsString("p.post_type = 'peepso-group'", $sql);
            self::assertStringContainsString("p.post_status = 'publish'", $sql);
            self::assertStringContainsString("p.post_name <> ''", $sql);
        }

        public function testAnEmptyOpenListingIsEmptyNotUnknown(): void
        {
            $this->scripted('ok', []);

            self::assertSame([], PeepSoGroupRepository::openGroupsAfter(0, 10));
        }

        public function testAFailedOpenListingIsUnknown(): void
        {
            $this->scripted('error');

            self::assertNull(PeepSoGroupRepository::openGroupsAfter(0, 10));
        }

        /** ⚠ The pre-flush bail: stale rows must never be served as the open list. */
        public function testAPreFlushBailOnTheOpenListingIsUnknownNotTheStaleRows(): void
        {
            $wpdb = $this->scripted('preflush');
            $wpdb->last_result = [(object) ['id' => '43', 'slug' => 'secret-group', 'is_hall' => '0']];

            self::assertNull(PeepSoGroupRepository::openGroupsAfter(0, 10));
        }

        public function testOutOfRangeArgumentsAreRefusedAndAskNothing(): void
        {
            $wpdb = $this->scripted('ok', []);

            self::assertNull(PeepSoGroupRepository::openGroupsAfter(-1, 10));
            self::assertNull(PeepSoGroupRepository::openGroupsAfter(0, 0));
            self::assertNull(PeepSoGroupRepository::openGroupsAfter(0, PeepSoGroupRepository::OPEN_GROUPS_PAGE_MAX + 1));
            self::assertSame([], $wpdb->queries, 'refused, not clamped');

            self::assertSame([], PeepSoGroupRepository::openGroupsAfter(0, PeepSoGroupRepository::OPEN_GROUPS_PAGE_MAX));
            self::assertCount(1, $wpdb->queries, 'the maximum page is allowed');
        }

        // ── the SQL, executed ──────────────────────────────────────────

        /**
         * Group id => its privacy rows (meta_value), and the answer.
         *
         * @return array<int, array{list<string>, int}>
         */
        private static function grid(): array
        {
            return [
                40 => [[], 0],              // no privacy row: open (deliberate)
                41 => [['0'], 0],
                42 => [['1'], 1],
                43 => [['2'], 2],
                44 => [['0', '2'], 2],      // F-429: a two-row group is SECRET, whatever the order
                45 => [['2', '0'], 2],
                46 => [['1', '0'], 1],
                47 => [['0', '1', '2'], 2],
                48 => [['2 '], 2],          // CAST AS UNSIGNED, as notSecretSql
                49 => [['7'], 0],           // a stray value is open, as notSecretSql
                50 => [['abc'], 0],
                // Every reader CASTs, so these read 2 in all four.
                51 => [[' 2'], 2],
                52 => [['02'], 2],
                53 => [['1', '2'], 2],      // two non-open rows: still ONE deny-list entry
            ];
        }

        /**
         * Rows outside the grid, for the deny lists only: a TRASHED secret
         * group (the strict list keeps it, the lenient one does not) and a
         * non-group post carrying a privacy row (neither list).
         */
        private const TRASHED_SECRET_GROUP = 60;
        private const NON_GROUP_POST       = 61;

        public function testTheRuleExecutesOnSqlite(): void
        {
            if (!extension_loaded('pdo_sqlite')) {
                if (getenv('GITHUB_ACTIONS') === 'true') {
                    self::fail('pdo_sqlite must be loaded in CI: this is the only test that executes the group privacy read.');
                }
                self::markTestSkipped('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite).');
            }

            $this->assertRuleOnEngine(self::sqlite());
        }

        public function testTheRuleExecutesOnMysql(): void
        {
            $this->assertRuleOnEngine(self::mysql());
        }

        /**
         * The deny lists, executed. Separate processes: they need the
         * object-cache stubs, which must not leak into this in-process file.
         */
        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testTheDenyListsExecuteOnSqlite(): void
        {
            $this->assertDenyListsOnEngine(self::sqlite());
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testTheDenyListsExecuteOnMysql(): void
        {
            $this->assertDenyListsOnEngine(self::mysql());
        }

        public function testTheOpenListingExecutesOnSqlite(): void
        {
            $this->assertOpenListingOnEngine(self::sqlite());
        }

        public function testTheOpenListingExecutesOnMysql(): void
        {
            $this->assertOpenListingOnEngine(self::mysql());
        }

        /**
         * F-518: the open listing, executed on the seeded engine. A group is
         * listed only when a privacy row reads 0 and none reads 1 or 2, it is
         * a published peepso-group, and it has a slug. Pages walk in id order.
         *
         * Two edges read OPEN for gates but are deliberately NOT listed: no
         * privacy row (40) and a stray value (49, reads 7). A public listing
         * claims a group is open only when its row says so.
         */
        private function assertOpenListingOnEngine(PDO $pdo): void
        {
            // Plain tables: the listing reads postmeta in three subqueries.
            $expected = $this->seedEngine($pdo, false);
            try {
                $this->assertOpenListingOnSeededEngine($pdo, $expected);
            } finally {
                $this->dropEngineTables($pdo);
            }
        }

        private function dropEngineTables(PDO $pdo): void
        {
            $pdo->exec('DROP TABLE IF EXISTS ' . self::PREFIX . 'posts');
            $pdo->exec('DROP TABLE IF EXISTS ' . self::PREFIX . 'postmeta');
        }

        /** @param array<int, int> $expected */
        private function assertOpenListingOnSeededEngine(PDO $pdo, array $expected): void
        {

            $setName = $pdo->prepare('UPDATE ' . self::PREFIX . 'posts SET post_name = ? WHERE ID = ?');
            foreach (array_keys($expected) as $groupId) {
                $setName->execute(['g' . $groupId, $groupId]);
            }

            $metaId     = 1000;
            $insertPost = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'posts (ID, post_type, post_status, post_name) VALUES (?, ?, ?, ?)');
            $insertMeta = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'postmeta (meta_id, post_id, meta_key, meta_value) VALUES (?, ?, ?, ?)');
            $openPost   = static function (int $id, string $type, string $status, string $name, ?string $kind) use ($insertPost, $insertMeta, &$metaId): void {
                $insertPost->execute([$id, $type, $status, $name]);
                $insertMeta->execute([$metaId++, $id, PeepSoGroupPrivacy::META_KEY, '0']);
                if ($kind !== null) {
                    $insertMeta->execute([$metaId++, $id, '_bcc_group_kind', $kind]);
                }
            };
            $openPost(70, 'peepso-group', 'publish', 'h70', 'hall');    // an open Hall
            $openPost(71, 'peepso-group', 'publish', '', null);         // no slug: no URL to list
            $openPost(72, 'peepso-group', 'draft', 'd72', null);        // not published
            $openPost(73, 'post', 'publish', 'p73', null);              // not a group
            $openPost(74, 'peepso-group', 'publish', 'c74', 'holders'); // open, not a Hall

            $all = PeepSoGroupRepository::openGroupsAfter(0, PeepSoGroupRepository::OPEN_GROUPS_PAGE_MAX);
            self::assertSame(
                [
                    // 50 is 'abc': the class's one reading CASTs it to 0.
                    ['id' => 41, 'slug' => 'g41', 'is_hall' => false],
                    ['id' => 50, 'slug' => 'g50', 'is_hall' => false],
                    ['id' => 70, 'slug' => 'h70', 'is_hall' => true],
                    ['id' => 74, 'slug' => 'c74', 'is_hall' => false],
                ],
                $all,
                'open, published, slugged groups only, ascending'
            );

            $ids = static fn (?array $page): array => array_column($page ?? [], 'id');
            self::assertSame([41, 50], $ids(PeepSoGroupRepository::openGroupsAfter(0, 2)), 'first page');
            self::assertSame([70, 74], $ids(PeepSoGroupRepository::openGroupsAfter(50, 2)), 'next page starts after the cursor');
            self::assertSame([], PeepSoGroupRepository::openGroupsAfter(74, 2), 'past the end');

            // Never wider than the gate: everything listed reads open on the
            // single read. The two gate-open edges are left out on purpose.
            foreach ([41, 50, 70, 74] as $groupId) {
                self::assertSame(PeepSoGroupPrivacy::OPEN, PeepSoGroupPrivacy::privacyForGroup($groupId), "group {$groupId} reads open");
            }
            self::assertSame(PeepSoGroupPrivacy::OPEN, $expected[40], 'no row: open for gates, not listed');
            self::assertSame(PeepSoGroupPrivacy::OPEN, $expected[49], 'stray value: open for gates, not listed');
        }

        private static function sqlite(): PDO
        {
            if (!extension_loaded('pdo_sqlite')) {
                if (getenv('GITHUB_ACTIONS') === 'true') {
                    self::fail('pdo_sqlite must be loaded in CI: this is the only test that executes the group privacy read.');
                }
                self::markTestSkipped('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite).');
            }

            return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }

        private static function mysql(): PDO
        {
            $port = getenv('BCC_TEST_DB_PORT');
            if ($port === false || $port === '') {
                self::markTestSkipped('Opt-in: set BCC_TEST_DB_PORT (and _HOST/_USER/_PASS/_NAME) to run the read on MySQL.');
            }
            if (!extension_loaded('pdo_mysql')) {
                self::markTestSkipped('pdo_mysql is not loaded (run with -d extension=pdo_mysql).');
            }

            return new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
                    (int) $port,
                    getenv('BCC_TEST_DB_NAME') ?: 'bcc_test'
                ),
                getenv('BCC_TEST_DB_USER') ?: 'root',
                getenv('BCC_TEST_DB_PASS') ?: 'root',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        /**
         * Create the two tables, seed the grid plus the deny-list extras,
         * and install a PDO-backed `$wpdb`. Returns each grid group's answer.
         *
         * @return array<int, int>
         */
        private function seedEngine(PDO $pdo, bool $temporary = true): array
        {
            // ⚠ MySQL cannot open a TEMPORARY table twice in one statement
            // (error 1137), and a query that reads postmeta in more than one
            // subquery does exactly that. Such a test passes `false`: plain
            // tables, dropped first and by dropEngineTables() after.
            $kind = $temporary ? 'TEMPORARY TABLE' : 'TABLE';
            if (!$temporary) {
                $this->dropEngineTables($pdo);
            }
            $pdo->exec("CREATE {$kind} " . self::PREFIX . 'posts ('
                . "ID INT NOT NULL, post_type VARCHAR(20) NOT NULL DEFAULT 'peepso-group', post_status VARCHAR(20) NOT NULL DEFAULT 'publish',"
                . " post_name VARCHAR(200) NOT NULL DEFAULT '')");
            $pdo->exec("CREATE {$kind} " . self::PREFIX . 'postmeta ('
                . 'meta_id INT NOT NULL, post_id INT NOT NULL, meta_key VARCHAR(255) NOT NULL, meta_value TEXT NULL)');

            $metaId = 1;
            $insertPost = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'posts (ID) VALUES (?)');
            $insertMeta = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'postmeta (meta_id, post_id, meta_key, meta_value) VALUES (?, ?, ?, ?)');
            foreach (self::grid() as $groupId => [$values]) {
                $insertPost->execute([$groupId]);
                foreach ($values as $value) {
                    $insertMeta->execute([$metaId++, $groupId, PeepSoGroupPrivacy::META_KEY, $value]);
                }
            }
            // Another key on the same group must not count.
            $insertMeta->execute([$metaId++, 41, '_bcc_group_kind', '2']);

            $pdo->prepare('INSERT INTO ' . self::PREFIX . "posts (ID, post_status) VALUES (?, 'trash')")
                ->execute([self::TRASHED_SECRET_GROUP]);
            $insertMeta->execute([$metaId++, self::TRASHED_SECRET_GROUP, PeepSoGroupPrivacy::META_KEY, '2']);
            $pdo->prepare('INSERT INTO ' . self::PREFIX . "posts (ID, post_type) VALUES (?, 'post')")
                ->execute([self::NON_GROUP_POST]);
            $insertMeta->execute([$metaId++, self::NON_GROUP_POST, PeepSoGroupPrivacy::META_KEY, '2']);

            $GLOBALS['wpdb'] = new PdoWpdb($pdo, self::PREFIX);

            $expected = [];
            foreach (self::grid() as $groupId => [, $answer]) {
                $expected[$groupId] = $answer;
            }

            return $expected;
        }

        /**
         * Both deny lists on the seeded engine: a group is denied exactly when
         * `privacyForGroup()` answers 1 or 2, ONCE however many rows it has,
         * newest first. Strict also keeps the trashed secret group.
         */
        private function assertDenyListsOnEngine(PDO $pdo): void
        {
            require_once __DIR__ . '/Stubs/object-cache-stubs.php';
            \BccTestPersistentCache::reset();

            $expected = $this->seedEngine($pdo);

            $nonOpen = array_keys(array_filter($expected, static fn (int $v): bool => $v !== PeepSoGroupPrivacy::OPEN));
            rsort($nonOpen);
            foreach ($nonOpen as $groupId) {
                self::assertSame($expected[$groupId], PeepSoGroupPrivacy::privacyForGroup($groupId), "group {$groupId} reads non-open");
            }

            self::assertSame($nonOpen, PeepSoGroupRepository::getNonOpenGroupIds(500), 'the lenient deny list');

            $strict = array_merge([self::TRASHED_SECRET_GROUP], $nonOpen);
            self::assertSame($strict, PeepSoGroupRepository::getNonOpenGroupIdsStrict(500), 'the strict deny list');

            // At exactly the number of non-open groups the strict list still
            // answers: a group's second row no longer takes a slot and trips
            // the truncation detector.
            \BccTestPersistentCache::reset();
            self::assertSame($strict, PeepSoGroupRepository::getNonOpenGroupIdsStrict(count($strict)), 'one slot per group');
        }

        private function assertRuleOnEngine(PDO $pdo): void
        {
            $expected = $this->seedEngine($pdo);

            self::assertSame($expected, PeepSoGroupPrivacy::privacyForGroups(array_keys($expected)), 'the batch read');
            foreach ($expected as $groupId => $answer) {
                self::assertSame($answer, PeepSoGroupPrivacy::privacyForGroup($groupId), "the single read, group {$groupId}");
            }

            // Agreement with the list predicate on the same data: a group is
            // listed exactly when the single read does not say secret.
            $listed = array_map('intval', $pdo->query(
                'SELECT p.ID FROM ' . self::PREFIX . 'posts p WHERE ' . PeepSoGroupPrivacy::notSecretSql('p.ID') . ' ORDER BY p.ID'
            )->fetchAll(PDO::FETCH_COLUMN));
            $notSecret = array_keys(array_filter($expected, static fn (int $v): bool => $v !== PeepSoGroupPrivacy::SECRET));
            self::assertSame($notSecret, $listed, 'notSecretSql and privacyForGroup must agree');
        }
    }

    /**
     * A `$wpdb` that executes on PDO: enough of `query()` / `last_result` /
     * `last_error` for `ProbesReads` to run unmodified.
     */
    final class PdoWpdb
    {
        public string $postmeta;
        public string $posts;
        public string $last_error = '';
        /** @var list<object> */
        public array $last_result = [];

        public function __construct(private PDO $pdo, string $prefix)
        {
            $this->postmeta = $prefix . 'postmeta';
            $this->posts    = $prefix . 'posts';
        }

        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            $next = 0;

            return (string) preg_replace_callback(
                '/%[ds]/',
                function (array $m) use (&$next, $args): string {
                    $value = $args[$next++] ?? '';

                    return $m[0] === '%d' ? (string) (int) $value : $this->pdo->quote((string) $value);
                },
                $query
            );
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $this->last_error  = '';
            $this->last_result = [];
            try {
                $statement = $this->pdo->query($sql);
            } catch (\PDOException $e) {
                $this->last_error = $e->getMessage();

                return false;
            }
            $this->last_result = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_OBJ);

            return count($this->last_result);
        }

        /**
         * Like wpdb: ALWAYS an array, the first column as strings. With a
         * null `$sql` it reads the `last_result` already fetched.
         *
         * @return list<string>
         */
        public function get_col(?string $sql = null): array
        {
            if ($sql !== null && $sql !== '') {
                $this->query($sql);
            }

            return array_map(static fn (object $r): string => (string) array_values(get_object_vars($r))[0], $this->last_result);
        }
    }
}
