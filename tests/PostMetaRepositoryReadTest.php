<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\PostMetaRead {

    use BCC\Core\Repositories\PostMetaRepository;
    use BCC\Core\Tests\Stubs\UserMetaWpdb;
    use PDO;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * The one guarded post-meta reader (F-463), the post twin of
     * UserMetaRepository on the shared ReadsMetaRows read.
     *
     * Two layers:
     *   - the CONTRACT, over the scripted `$wpdb` (tests/Stubs/usermeta-wpdb.php
     *     with `$metaType = 'post'`): an untrusted read is null, the bounds
     *     refuse rather than truncate, duplicate rows all come back in meta_id
     *     order, and nothing is memoised;
     *   - the SQL, EXECUTED on SQLite (always) and MySQL (opt-in via
     *     BCC_TEST_DB_PORT).
     */
    #[CoversClass(PostMetaRepository::class)]
    final class PostMetaRepositoryReadTest extends TestCase
    {
        private const PREFIX = 'bcc_pmr_';

        /** @var mixed */
        private $priorWpdb;

        protected function setUp(): void
        {
            require_once __DIR__ . '/Stubs/usermeta-wpdb.php';
            $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
            $GLOBALS['__pm_meta'] = [
                40 => ['_bcc_group_kind' => 'holders', '_bcc_trust_gate_min' => ['25', '50']],
                42 => ['_bcc_group_kind' => 'hall'],
            ];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['__pm_meta']);
            if ($this->priorWpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $this->priorWpdb;
            }
        }

        private function db(string ...$modes): UserMetaWpdb
        {
            $db        = new UserMetaWpdb('__pm_meta', 'post');
            $db->modes = array_values($modes);
            $GLOBALS['wpdb'] = $db;

            return $db;
        }

        // ── the contract ───────────────────────────────────────────────

        public function testATrustedReadGivesEveryValueInMetaIdOrder(): void
        {
            $db = $this->db();

            self::assertSame(
                [
                    40 => ['_bcc_group_kind' => ['holders'], '_bcc_trust_gate_min' => ['25', '50']],
                    42 => ['_bcc_group_kind' => ['hall']],
                ],
                PostMetaRepository::valuesOrUnknown([40, 41, 42], ['_bcc_group_kind', '_bcc_trust_gate_min']),
                'no row = the key absent; a post with none = no entry; duplicates all returned'
            );
            self::assertSame(1, $db->metaQueries, 'one query for the batch');
        }

        public function testEmptyOrNonPositiveInputReadsNothing(): void
        {
            $db = $this->db();

            self::assertSame([], PostMetaRepository::valuesOrUnknown([], ['a']));
            self::assertSame([], PostMetaRepository::valuesOrUnknown([0, -4], ['a']));
            self::assertSame([], PostMetaRepository::valuesOrUnknown([40], []));
            self::assertSame([], PostMetaRepository::valuesOrUnknown([40], ['']));
            self::assertSame(0, $db->metaQueries);
        }

        public function testTooManyKeysOrPostsIsRefusedNotTruncated(): void
        {
            $db = $this->db();

            $keys = array_map(static fn (int $i): string => 'k' . $i, range(1, PostMetaRepository::MAX_KEYS + 1));
            self::assertNull(PostMetaRepository::valuesOrUnknown([40], $keys));
            self::assertNull(PostMetaRepository::valuesOrUnknown(range(1, PostMetaRepository::BATCH_MAX + 1), ['a']));
            self::assertSame(0, $db->metaQueries, 'refused before any query');
        }

        /** @return iterable<string, array{string}> */
        public static function untrusted(): iterable
        {
            yield 'a driver error'     => ['error'];
            yield 'the pre-flush bail' => ['preflush'];
        }

        /**
         * The pre-flush bail leaves the PREVIOUS query's rows in last_result:
         * here a row that looks exactly like a "plain group, no minimum"
         * answer. A reader that trusted get_results() plus last_error would
         * return it.
         */
        #[DataProvider('untrusted')]
        public function testAReadThatCannotBeTrustedIsNull(string $mode): void
        {
            $db              = $this->db($mode);
            $db->last_result = [(object) ['post_id' => '40', 'meta_key' => '_bcc_group_kind', 'meta_value' => '']];

            self::assertNull(PostMetaRepository::valuesOrUnknown([40], ['_bcc_group_kind']));
        }

        /**
         * ⚠ NO MEMO. A repeat reads the table again, so a failure later in the
         * request is never masked by an answer from earlier, and an answer from
         * earlier is never replayed after the table changed.
         */
        public function testEveryCallReadsTheTableNoMemo(): void
        {
            $db = $this->db('ok', 'error');

            self::assertSame([42 => ['_bcc_group_kind' => ['hall']]], PostMetaRepository::valuesOrUnknown([42], ['_bcc_group_kind']));
            self::assertNull(PostMetaRepository::valuesOrUnknown([42], ['_bcc_group_kind']), 'the second read failed: unknown, not the first answer');
            self::assertSame(2, $db->metaQueries);

            $GLOBALS['__pm_meta'][42]['_bcc_group_kind'] = 'delegators';
            self::assertSame([42 => ['_bcc_group_kind' => ['delegators']]], PostMetaRepository::valuesOrUnknown([42], ['_bcc_group_kind']), 'the table, not a replay');
        }

        public function testAChunkThatFillsItsLimitIsRefused(): void
        {
            $GLOBALS['__pm_meta'] = [7 => ['a' => ['1', '2', '3', '4']]];
            $this->db();
            self::assertSame([7 => ['a' => ['1', '2', '3', '4']]], PostMetaRepository::valuesOrUnknown([7], ['a']), 'under the limit');

            $GLOBALS['__pm_meta'] = [7 => ['a' => ['1', '2', '3', '4', '5']]];
            $this->db();
            self::assertNull(
                PostMetaRepository::valuesOrUnknown([7], ['a']),
                'LIMIT = 1 post x 1 key x 5 reached: the newest rows may be cut'
            );
        }

        public function testOneFailedChunkFailsTheWholeAnswer(): void
        {
            $ids = range(1, PostMetaRepository::CHUNK_POSTS + 1);
            $db  = $this->db('ok', 'error');

            self::assertNull(PostMetaRepository::valuesOrUnknown($ids, ['_bcc_group_kind']));
            self::assertSame(2, $db->metaQueries, 'read in two chunks');
        }

        public function testARowForAPostNobodyAskedForMeansTheRowsAreNotOurs(): void
        {
            $db               = $this->db();
            $db->scriptedRows = [(object) ['post_id' => '99', 'meta_key' => 'a', 'meta_value' => '1']];

            self::assertNull(PostMetaRepository::valuesOrUnknown([40], ['a']));
        }

        public function testACaseVariantKeyIsSkippedAsTheMetaApiSkipsIt(): void
        {
            $db               = $this->db();
            $db->scriptedRows = [
                (object) ['post_id' => '40', 'meta_key' => '_BCC_GROUP_KIND', 'meta_value' => ''],
                (object) ['post_id' => '40', 'meta_key' => '_bcc_group_kind', 'meta_value' => 'holders'],
            ];

            self::assertSame([40 => ['_bcc_group_kind' => ['holders']]], PostMetaRepository::valuesOrUnknown([40], ['_bcc_group_kind']));
        }

        // ── the SQL, executed ──────────────────────────────────────────

        public function testTheReadExecutesOnSqlite(): void
        {
            if (!extension_loaded('pdo_sqlite')) {
                if (getenv('GITHUB_ACTIONS') === 'true') {
                    self::fail('pdo_sqlite must be loaded in CI: this test executes the post-meta read.');
                }
                self::markTestSkipped('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite).');
            }

            $this->assertReadOnEngine(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), false);
        }

        public function testTheReadExecutesOnMysql(): void
        {
            $port = getenv('BCC_TEST_DB_PORT');
            if ($port === false || $port === '') {
                self::markTestSkipped('Opt-in: set BCC_TEST_DB_PORT (and _HOST/_USER/_PASS/_NAME) to run the read on MySQL.');
            }
            if (!extension_loaded('pdo_mysql')) {
                self::markTestSkipped('pdo_mysql is not loaded (run with -d extension=pdo_mysql).');
            }

            $this->assertReadOnEngine(new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
                    (int) $port,
                    getenv('BCC_TEST_DB_NAME') ?: 'bcc_test'
                ),
                getenv('BCC_TEST_DB_USER') ?: 'root',
                getenv('BCC_TEST_DB_PASS') ?: 'root',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            ), true);
        }

        private function assertReadOnEngine(PDO $pdo, bool $mysql): void
        {
            $pdo->exec('CREATE TEMPORARY TABLE ' . self::PREFIX . 'postmeta ('
                . 'meta_id INT NOT NULL, post_id INT NOT NULL, meta_key VARCHAR(255) NULL, meta_value TEXT NULL)'
                . ($mysql ? ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
            $insert = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'postmeta (meta_id, post_id, meta_key, meta_value) VALUES (?, ?, ?, ?)');
            foreach ([
                [5, 10, '_bcc_trust_gate_min', '50'],
                [2, 10, '_bcc_trust_gate_min', '25'],       // older row, lower meta_id: first
                [3, 10, '_bcc_group_kind', 'holders'],
                [4, 10, 'other', 'ignored'],
                [6, 11, '_BCC_GROUP_KIND', 'hall'],          // a case variant: matched by _ci on MySQL, skipped
                [7, 12, '_bcc_group_kind', "it's raw"],      // quoted and returned raw
            ] as $row) {
                $insert->execute($row);
            }

            $GLOBALS['wpdb'] = new PdoWpdb($pdo, self::PREFIX);

            self::assertSame(
                [
                    10 => ['_bcc_group_kind' => ['holders'], '_bcc_trust_gate_min' => ['25', '50']],
                    12 => ['_bcc_group_kind' => ["it's raw"]],
                ],
                PostMetaRepository::valuesOrUnknown([10, 11, 12, 13], ['_bcc_group_kind', '_bcc_trust_gate_min'])
            );
        }
    }

    /**
     * A `$wpdb` that executes on PDO: enough of `prepare()` / `query()` /
     * `last_result` / `last_error` for ProbesReads to run unmodified.
     */
    final class PdoWpdb
    {
        public string $postmeta;
        public string $last_error = '';
        /** @var list<object> */
        public array $last_result = [];

        public function __construct(private PDO $pdo, string $prefix)
        {
            $this->postmeta = $prefix . 'postmeta';
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
