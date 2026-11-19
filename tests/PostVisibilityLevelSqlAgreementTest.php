<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\VisibilityLevelSql;

use BCC\Core\Feed\PostVisibilityLevel;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Tests\Stubs\RenderingWpdb;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/rendering-wpdb.php';

/**
 * ⚠⚠ THE §F-02 CLAUSE AND PostVisibilityLevel MUST GIVE THE SAME ANSWER.
 *
 * The SQL clause decides what the feed and the permalink show.
 * `PostVisibilityLevel::effective()` is the same rule for PHP callers.
 * If the two drift, a post can be listed but not repostable, or the
 * reverse, and nothing else notices: each looks right on its own.
 *
 * So this EXECUTES the real statement. `getById()` renders it through the
 * `$wpdb` double, exactly as MySQL would receive it, and it runs against
 * a three-table copy of the schema: activities, posts, peepso_users. That
 * goes over a grid of stored values:
 *   - act_access: NULL, -1, 0, the four rungs, the values between them,
 *     and 255, the column's ceiling;
 *   - the author's usr_profile_acc: the same kinds of values, plus an
 *     author with NO `peepso_users` row.
 *
 * Every pair is read by four viewers: signed out, a member who isn't a
 * friend, the author's friend, and the author. Each combination of
 * yes/no answers matches exactly one rung, so a level the helper gets
 * wrong cannot hide.
 *
 * Engines:
 *   - SQLite, always. `GREATEST(` becomes `MAX(` and `IF(` becomes
 *     `IIF(`; nothing else is rewritten. ⚠ It FAILS, not skips, in
 *     GitHub Actions without pdo_sqlite, because this is the only test
 *     in bcc-core that runs the clause instead of reading its text.
 *   - MySQL, opt-in: set BCC_TEST_DB_PORT (with _HOST, _USER, _PASS and
 *     _NAME, defaults 127.0.0.1 / root / root / bcc_test) and load
 *     pdo_mysql. The statement runs UNTRANSLATED. Tables are TEMPORARY,
 *     under their own prefix, so nothing persists and no real table is
 *     touched.
 *
 * ⚠ The grid stores NULL and -1 in act_access, which the real column
 * (TINYINT(3) UNSIGNED NOT NULL) cannot hold. The columns here are a
 * signed SMALLINT NULL on purpose: the clause handles those values, so
 * the helper must agree on them too.
 */
#[CoversClass(PostVisibilityLevel::class)]
#[CoversClass(PeepSoActivityRepository::class)]
final class PostVisibilityLevelSqlAgreementTest extends TestCase
{
    private const PREFIX  = 'bcc_f02lvl_';
    private const ACT_ID  = 4242;
    private const POST_ID = 99;
    private const AUTHOR  = 5;
    private const MEMBER  = 42;

    /** Marks "the author has no peepso_users row" in the grid. */
    private const NO_ROW = 'no row';

    /** @var list<int|null> */
    private const ACT_ACCESS = [null, -1, 0, 1, 10, 11, 15, 20, 21, 25, 30, 31, 35, 40, 255];

    /** @var list<int|null|string> */
    private const PROFILE_ACC = [self::NO_ROW, null, -1, 0, 10, 15, 20, 25, 30, 35, 40, 127];

    /** @var mixed */
    private $priorWpdb;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/nonopen-groups-seed.php';
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

    public function testTheClauseAgreesWithTheHelperOnSqlite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            if (getenv('GITHUB_ACTIONS') === 'true') {
                self::fail('pdo_sqlite must be loaded in CI: this is the only test that executes the F-02 clause.');
            }
            self::markTestSkipped('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite).');
        }

        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->assertAgreement($pdo, static fn (string $sql): string => self::toSqlite($sql));
    }

    public function testTheClauseAgreesWithTheHelperOnMysql(): void
    {
        $port = getenv('BCC_TEST_DB_PORT');
        if ($port === false || $port === '') {
            self::markTestSkipped('Opt-in: set BCC_TEST_DB_PORT (and _HOST/_USER/_PASS/_NAME) to run the clause on MySQL.');
        }
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is not loaded (run with -d extension=pdo_mysql).');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            (int) $port,
            getenv('BCC_TEST_DB_NAME') ?: 'bcc_test'
        );
        $pdo = new PDO(
            $dsn,
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->assertAgreement($pdo, static fn (string $sql): string => $sql);
    }

    /**
     * @param \Closure(string): string $dialect
     */
    private function assertAgreement(PDO $pdo, \Closure $dialect): void
    {
        $this->createTables($pdo);

        // The statement depends on the viewer, never on the row, so each
        // viewer's is rendered once.
        $statements = [];
        foreach (self::viewers() as $label => [$viewerId, $friendIds]) {
            $statements[$label] = $dialect($this->renderGetById($viewerId, $friendIds));
        }

        $mismatches = [];
        $levelsSeen = [];
        foreach (self::ACT_ACCESS as $act) {
            foreach (self::PROFILE_ACC as $profile) {
                $this->seed($pdo, $act, $profile);

                // No row and a NULL column both reach the helper as null.
                $level = PostVisibilityLevel::effective($act, is_string($profile) ? null : $profile);
                $levelsSeen[$level] = true;

                $expected = [
                    'signed out'           => $level <= PostVisibilityLevel::PUBLIC,
                    'member, not a friend' => $level <= PostVisibilityLevel::MEMBERS,
                    'the author\'s friend' => $level <= PostVisibilityLevel::FRIENDS,
                    'the author'           => true,
                ];

                foreach ($statements as $label => $sql) {
                    $rows    = $pdo->query($sql);
                    $visible = $rows !== false && $rows->fetch(PDO::FETCH_ASSOC) !== false;
                    if ($visible !== $expected[$label]) {
                        $mismatches[] = sprintf(
                            'act_access=%s, profile=%s, viewer=%s: SQL %s, helper level %d says %s',
                            var_export($act, true),
                            is_string($profile) ? $profile : var_export($profile, true),
                            $label,
                            $visible ? 'shows it' : 'hides it',
                            $level,
                            $expected[$label] ? 'show' : 'hide'
                        );
                    }
                }
            }
        }

        self::assertSame([], $mismatches, "the F-02 clause and PostVisibilityLevel disagree:\n" . implode("\n", $mismatches));

        // Not vacuous: the grid reaches every rung, so each of the four
        // answers above was actually compared.
        ksort($levelsSeen);
        self::assertSame([10, 20, 30, 40], array_keys($levelsSeen));
    }

    /**
     * Viewer label => [viewerId, viewerFriendIds].
     *
     * @return array<string, array{int, list<int>|null}>
     */
    private static function viewers(): array
    {
        return [
            'signed out'           => [0, null],
            'member, not a friend' => [self::MEMBER, [7]],
            'the author\'s friend' => [self::MEMBER, [self::AUTHOR]],
            'the author'           => [self::AUTHOR, null],
        ];
    }

    /**
     * @param list<int>|null $friendIds
     */
    private function renderGetById(int $viewerId, ?array $friendIds): string
    {
        $wpdb           = new RenderingWpdb();
        $wpdb->prefix   = self::PREFIX;
        $wpdb->posts    = self::PREFIX . 'posts';
        $wpdb->postmeta = self::PREFIX . 'postmeta';
        $GLOBALS['wpdb'] = $wpdb;

        PeepSoActivityRepository::getById(
            self::ACT_ID,
            null,
            viewerFriendIds: $friendIds,
            enforcePostVisibility: true,
            viewerId: $viewerId
        );

        $sql = trim($wpdb->lastSql);
        self::assertStringContainsString('LEFT JOIN ' . self::PREFIX . 'peepso_users pu', $sql, 'the F-02 clause must be in the statement');
        self::assertStringNotContainsString('wp_', $sql, 'every table must be the test copy');

        return $sql;
    }

    /**
     * The minimal MySQL → SQLite rewrite: two function names, nothing
     * else. Each must actually occur, so a rewrite that silently matched
     * nothing cannot pass for a translation.
     */
    private static function toSqlite(string $mysql): string
    {
        $sql = (string) preg_replace('/\bGREATEST\(/', 'MAX(', $mysql, -1, $greatest);
        $sql = (string) preg_replace('/\bIF\(/', 'IIF(', $sql, -1, $if);

        self::assertGreaterThan(0, $greatest, 'expected GREATEST( in the clause');
        self::assertGreaterThan(0, $if, 'expected IF( in the clause');

        return $sql;
    }

    private function createTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TEMPORARY TABLE ' . self::PREFIX . 'peepso_activities ('
            . 'act_id INT NOT NULL, act_owner_id INT NOT NULL, act_module_id INT NOT NULL,'
            . ' act_external_id INT NOT NULL, act_access SMALLINT NULL, act_repost_id INT NULL)');
        $pdo->exec('CREATE TEMPORARY TABLE ' . self::PREFIX . 'posts ('
            . 'ID INT NOT NULL, post_author INT NOT NULL, post_date_gmt DATETIME NOT NULL,'
            . ' post_status VARCHAR(20) NOT NULL)');
        $pdo->exec('CREATE TEMPORARY TABLE ' . self::PREFIX . 'peepso_users ('
            . 'usr_id INT NOT NULL, usr_profile_acc SMALLINT NULL)');
    }

    private function seed(PDO $pdo, ?int $act, int|string|null $profile): void
    {
        foreach (['peepso_activities', 'posts', 'peepso_users'] as $table) {
            $pdo->exec('DELETE FROM ' . self::PREFIX . $table);
        }

        $activity = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'peepso_activities'
            . ' (act_id, act_owner_id, act_module_id, act_external_id, act_access, act_repost_id)'
            . ' VALUES (?, ?, 1, ?, ?, NULL)');
        $activity->bindValue(1, self::ACT_ID, PDO::PARAM_INT);
        $activity->bindValue(2, self::AUTHOR, PDO::PARAM_INT);
        $activity->bindValue(3, self::POST_ID, PDO::PARAM_INT);
        $activity->bindValue(4, $act, $act === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $activity->execute();

        $post = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'posts (ID, post_author, post_date_gmt, post_status)'
            . " VALUES (?, ?, '2026-11-19 00:00:00', 'publish')");
        $post->execute([self::POST_ID, self::AUTHOR]);

        if ($profile === self::NO_ROW) {
            return;
        }
        $user = $pdo->prepare('INSERT INTO ' . self::PREFIX . 'peepso_users (usr_id, usr_profile_acc) VALUES (?, ?)');
        $user->bindValue(1, self::AUTHOR, PDO::PARAM_INT);
        $user->bindValue(2, $profile, $profile === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $user->execute();
    }
}
