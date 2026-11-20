<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\GroupPrivacyRead {

    use BCC\Core\Repositories\PeepSoGroupPrivacy;
    use PDO;
    use PHPUnit\Framework\Attributes\CoversClass;
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
     *     cannot disagree with the list predicate.
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
            ];
        }

        public function testTheRuleExecutesOnSqlite(): void
        {
            if (!extension_loaded('pdo_sqlite')) {
                if (getenv('GITHUB_ACTIONS') === 'true') {
                    self::fail('pdo_sqlite must be loaded in CI: this is the only test that executes the group privacy read.');
                }
                self::markTestSkipped('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite).');
            }

            $this->assertRuleOnEngine(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
        }

        public function testTheRuleExecutesOnMysql(): void
        {
            $port = getenv('BCC_TEST_DB_PORT');
            if ($port === false || $port === '') {
                self::markTestSkipped('Opt-in: set BCC_TEST_DB_PORT (and _HOST/_USER/_PASS/_NAME) to run the read on MySQL.');
            }
            if (!extension_loaded('pdo_mysql')) {
                self::markTestSkipped('pdo_mysql is not loaded (run with -d extension=pdo_mysql).');
            }

            $this->assertRuleOnEngine(new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
                    (int) $port,
                    getenv('BCC_TEST_DB_NAME') ?: 'bcc_test'
                ),
                getenv('BCC_TEST_DB_USER') ?: 'root',
                getenv('BCC_TEST_DB_PASS') ?: 'root',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            ));
        }

        private function assertRuleOnEngine(PDO $pdo): void
        {
            $pdo->exec('CREATE TEMPORARY TABLE ' . self::PREFIX . 'posts (ID INT NOT NULL)');
            $pdo->exec('CREATE TEMPORARY TABLE ' . self::PREFIX . 'postmeta ('
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

            $wpdb = new PdoWpdb($pdo, self::PREFIX);
            $GLOBALS['wpdb'] = $wpdb;

            $expected = [];
            foreach (self::grid() as $groupId => [, $answer]) {
                $expected[$groupId] = $answer;
            }

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
    }
}
