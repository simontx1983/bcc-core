<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\UserMetaRead {

    use BCC\Core\Repositories\UserMetaRepository;
    use BCC\Core\Tests\Stubs\UserMetaWpdb;
    use PDO;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * The one guarded user-meta reader.
     *
     * Two layers:
     *   - the CONTRACT, over a scripted `$wpdb` (tests/Stubs/usermeta-wpdb.php):
     *     an untrusted read is null, the bounds refuse rather than truncate,
     *     the memo holds trusted answers only and `$fresh` bypasses it;
     *   - the SQL, EXECUTED on SQLite (always) and MySQL (opt-in via
     *     BCC_TEST_DB_PORT).
     */
    #[CoversClass(UserMetaRepository::class)]
    final class UserMetaRepositoryReadTest extends TestCase
    {
        private const PREFIX = 'bcc_umr_';

        /** @var mixed */
        private $priorWpdb;

        protected function setUp(): void
        {
            require_once __DIR__ . '/Stubs/usermeta-wpdb.php';
            $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
            self::resetMemo();
            $GLOBALS['__um_meta'] = [
                1 => ['a' => 'x', 'b' => ['0', '1']],
                3 => ['a' => 'z'],
            ];
        }

        protected function tearDown(): void
        {
            self::resetMemo();
            unset($GLOBALS['__um_meta']);
            if ($this->priorWpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $this->priorWpdb;
            }
        }

        private static function resetMemo(): void
        {
            (new \ReflectionProperty(UserMetaRepository::class, 'memo'))->setValue(null, []);
        }

        private function db(string ...$modes): UserMetaWpdb
        {
            $db        = new UserMetaWpdb();
            $db->modes = array_values($modes);
            $GLOBALS['wpdb'] = $db;

            return $db;
        }

        // ── the contract ───────────────────────────────────────────────

        public function testATrustedReadGivesEveryValueOrTheFirst(): void
        {
            $this->db();

            self::assertSame(
                [1 => ['a' => ['x'], 'b' => ['0', '1']], 3 => ['a' => ['z']]],
                UserMetaRepository::valuesOrUnknown([1, 2, 3], ['a', 'b']),
                'no row = the key absent; a user with none = no entry'
            );
            self::assertSame(
                [1 => ['a' => 'x', 'b' => '0'], 3 => ['a' => 'z']],
                UserMetaRepository::firstValuesOrUnknown([1, 2, 3], ['a', 'b']),
                'the first row, as get_user_meta(…, true)'
            );
        }

        public function testEmptyOrNonPositiveInputReadsNothing(): void
        {
            $db = $this->db();

            self::assertSame([], UserMetaRepository::valuesOrUnknown([], ['a']));
            self::assertSame([], UserMetaRepository::valuesOrUnknown([0, -4], ['a']));
            self::assertSame([], UserMetaRepository::valuesOrUnknown([1], []));
            self::assertSame([], UserMetaRepository::valuesOrUnknown([1], ['']));
            self::assertSame(0, $db->metaQueries);
        }

        public function testTooManyKeysOrUsersIsRefusedNotTruncated(): void
        {
            $db = $this->db();

            $keys = array_map(static fn (int $i): string => 'k' . $i, range(1, UserMetaRepository::MAX_KEYS + 1));
            self::assertNull(UserMetaRepository::valuesOrUnknown([1], $keys));
            self::assertNull(UserMetaRepository::valuesOrUnknown(range(1, UserMetaRepository::BATCH_MAX + 1), ['a']));
            self::assertSame(0, $db->metaQueries, 'refused before any query');
        }

        /** @return iterable<string, array{string}> */
        public static function untrusted(): iterable
        {
            yield 'a driver error'    => ['error'];
            yield 'the pre-flush bail' => ['preflush'];
        }

        /**
         * The pre-flush bail leaves the PREVIOUS query's rows in
         * last_result: here a row that looks exactly like an answer for this
         * user and key. A reader that trusted get_results() plus last_error
         * would return it.
         */
        #[DataProvider('untrusted')]
        public function testAReadThatCannotBeTrustedIsNullAndNotRemembered(string $mode): void
        {
            $stale = [(object) ['user_id' => '1', 'meta_key' => 'b', 'meta_value' => '0']];

            $db              = $this->db($mode);
            $db->last_result = $stale;
            self::assertNull(UserMetaRepository::valuesOrUnknown([1], ['b']));

            $db              = $this->db($mode);
            $db->last_result = $stale;
            self::assertNull(UserMetaRepository::firstValuesOrUnknown([1], ['b']));

            $this->db();
            self::assertSame([1 => ['b' => ['0', '1']]], UserMetaRepository::valuesOrUnknown([1], ['b']), 'healthy again: read, not replayed');
        }

        public function testAChunkThatFillsItsLimitIsRefused(): void
        {
            $GLOBALS['__um_meta'] = [7 => ['a' => ['1', '2', '3', '4']]];
            $this->db();
            self::assertSame([7 => ['a' => ['1', '2', '3', '4']]], UserMetaRepository::valuesOrUnknown([7], ['a']), 'under the limit');

            self::resetMemo();
            $GLOBALS['__um_meta'] = [7 => ['a' => ['1', '2', '3', '4', '5']]];
            $this->db();
            self::assertNull(
                UserMetaRepository::valuesOrUnknown([7], ['a']),
                'LIMIT = 1 user x 1 key x 5 reached: the newest rows may be cut'
            );
        }

        public function testOneFailedChunkFailsTheWholeAnswer(): void
        {
            $ids = range(1, UserMetaRepository::CHUNK_USERS + 1);
            $db  = $this->db('ok', 'error');

            self::assertNull(UserMetaRepository::valuesOrUnknown($ids, ['a']));
            self::assertSame(2, $db->metaQueries, 'read in two chunks');

            $db = $this->db();
            self::assertSame([1 => ['a' => ['x']], 3 => ['a' => ['z']]], UserMetaRepository::valuesOrUnknown($ids, ['a']));
        }

        public function testARowForAUserNobodyAskedForMeansTheRowsAreNotOurs(): void
        {
            $db               = $this->db();
            $db->scriptedRows = [(object) ['user_id' => '99', 'meta_key' => 'a', 'meta_value' => '1']];

            self::assertNull(UserMetaRepository::valuesOrUnknown([1], ['a']));
        }

        public function testACaseVariantKeyIsSkippedAsGetUserMetaSkipsIt(): void
        {
            $db               = $this->db();
            $db->scriptedRows = [
                (object) ['user_id' => '1', 'meta_key' => 'A', 'meta_value' => '1'],
                (object) ['user_id' => '1', 'meta_key' => 'a', 'meta_value' => '0'],
            ];

            self::assertSame([1 => ['a' => ['0']]], UserMetaRepository::valuesOrUnknown([1], ['a']));
        }

        public function testTheMemoServesARepeatAndFreshNeitherReadsNorWritesIt(): void
        {
            $db = $this->db();
            UserMetaRepository::valuesOrUnknown([1], ['a', 'b']);
            UserMetaRepository::valuesOrUnknown([1], ['b']);
            UserMetaRepository::firstValuesOrUnknown([1], ['a']);
            self::assertSame(1, $db->metaQueries, 'one read, then the memo');

            $GLOBALS['__um_meta'][1]['a'] = 'changed elsewhere';
            self::assertSame([1 => ['a' => 'changed elsewhere']], UserMetaRepository::firstValuesOrUnknown([1], ['a'], fresh: true));
            self::assertSame(2, $db->metaQueries, 'fresh always reads');
            self::assertSame([1 => ['a' => 'x']], UserMetaRepository::firstValuesOrUnknown([1], ['a']), 'and did not write the memo');

            UserMetaRepository::valuesOrUnknown([3], ['a'], fresh: true);
            UserMetaRepository::valuesOrUnknown([3], ['a']);
            self::assertSame(4, $db->metaQueries, 'a fresh read leaves nothing to serve later');
        }

        /**
         * The memo is dropped wholesale when full. It must never drop a user
         * THIS call serves from the memo: that user's flag would come back
         * as "no row". The memo is filled to its cap, with user 1 memoised
         * as hidden, then one call asks for user 1 and a new user.
         */
        public function testAFullMemoNeverDropsAUserThisCallServes(): void
        {
            $max  = (int) (new \ReflectionClassConstant(UserMetaRepository::class, 'MEMO_MAX'))->getValue();
            $memo = [1 => ['hide' => ['1']]];
            for ($id = 100000; count($memo) < $max; $id++) {
                $memo[$id] = ['hide' => []];
            }
            (new \ReflectionProperty(UserMetaRepository::class, 'memo'))->setValue(null, $memo);

            $GLOBALS['__um_meta'] = [1 => ['hide' => '1'], 2 => ['hide' => '0']];
            $this->db();

            self::assertSame(
                [1 => ['hide' => ['1']], 2 => ['hide' => ['0']]],
                UserMetaRepository::valuesOrUnknown([1, 2], ['hide']),
                'the memoised hidden flag survives the reset'
            );
        }

        public function testForgetDropsOneUsersMemo(): void
        {
            $db = $this->db();
            UserMetaRepository::valuesOrUnknown([1, 3], ['a']);
            $GLOBALS['__um_meta'][1]['a'] = 'new';

            UserMetaRepository::forget(1);
            self::assertSame([1 => ['a' => ['new']], 3 => ['a' => ['z']]], UserMetaRepository::valuesOrUnknown([1, 3], ['a']));
            self::assertSame(2, $db->metaQueries, 'only the forgotten user was read again');
        }

        // ── the SQL, executed ──────────────────────────────────────────

        public function testTheReadExecutesOnSqlite(): void
        {
            if (!extension_loaded('pdo_sqlite')) {
                if (getenv('GITHUB_ACTIONS') === 'true') {
                    self::fail('pdo_sqlite must be loaded in CI: this test executes the user-meta read.');
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
            $pdo->exec('CREATE TEMPORARY TABLE ' . self::PREFIX . 'usermeta ('
                . 'umeta_id INT NOT NULL, user_id INT NOT NULL, meta_key VARCHAR(255) NULL, meta_value TEXT NULL)'
                . ($mysql ? ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
            $insert = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (?, ?, ?, ?)');
            foreach ([
                [5, 10, 'flag', '1'],
                [2, 10, 'flag', '0'],       // older row, lower umeta_id: the FIRST
                [3, 10, 'handle', "o'brien"],
                [4, 10, 'other', 'ignored'],
                [6, 11, 'FLAG', '1'],       // a case variant: matched by _ci on MySQL, skipped
                [7, 12, 'flag', 'a:1:{i:0;s:1:"x";}'], // raw, never unserialised
            ] as $row) {
                $insert->execute($row);
            }

            $GLOBALS['wpdb'] = new PdoWpdb($pdo, self::PREFIX);

            self::assertSame(
                [10 => ['flag' => ['0', '1'], 'handle' => ["o'brien"]], 12 => ['flag' => ['a:1:{i:0;s:1:"x";}']]],
                UserMetaRepository::valuesOrUnknown([10, 11, 12, 13], ['flag', 'handle'], fresh: true)
            );
            self::assertSame(
                [10 => ['flag' => '0', 'handle' => "o'brien"], 12 => ['flag' => 'a:1:{i:0;s:1:"x";}']],
                UserMetaRepository::firstValuesOrUnknown([10, 11, 12, 13], ['flag', 'handle'], fresh: true)
            );
        }
    }

    /**
     * A `$wpdb` that executes on PDO: enough of `prepare()` / `query()` /
     * `last_result` / `last_error` for ProbesReads to run unmodified.
     */
    final class PdoWpdb
    {
        public string $usermeta;
        public string $last_error = '';
        /** @var list<object> */
        public array $last_result = [];

        public function __construct(private PDO $pdo, string $prefix)
        {
            $this->usermeta = $prefix . 'usermeta';
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
