<?php

declare(strict_types=1);

namespace BCC\Core\Feed\Tests\GroupFactsAllRow;

use BCC\Core\Feed\GroupSyndicationGate as G;
use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Core\Tests\Stubs\PdoWpdb;
use BCC\Core\Tests\Stubs\RenderingWpdb;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/pdo-wpdb.php';
require_once __DIR__ . '/Stubs/rendering-wpdb.php';

/**
 * DUPLICATE GROUP-FACTS ROWS FOLD STRICTEST-WINS, IN SQL AS IN PHP.
 *
 * A post should carry one row per group-facts key, but nothing enforces it.
 * A value that GRANTS something (`public_all`; privacy-at-post "non-open")
 * must count only when EVERY row of the key says it: one other row, a NULL
 * one included, withholds it, in either insertion (meta_id) order.
 *
 * This EXECUTES the SQL, over duplicate rows inserted in both orders:
 *   - GroupSyndicationGate::publicAllPredicate() (global feed, trending);
 *   - everyRowIn() with the non-member values (group teaser, profile parity);
 *   - sqlPredicate() with a known and an unknown non-open set, TRIM included;
 *   - getById()'s `bcc_post_visibility` / `bcc_privacy_at_post` columns,
 *     which must AGREE with the predicates on every case.
 *
 * Engines:
 *   - SQLite, always. ⚠ FAILS, not skips, in GitHub Actions without
 *     pdo_sqlite. getById()'s `GROUP_CONCAT(x ORDER BY … SEPARATOR ',')`
 *     becomes `GROUP_CONCAT(x, ',')` (the group-id list, not under test);
 *     nothing else is rewritten.
 *   - MySQL, opt-in: BCC_TEST_DB_PORT (with _HOST, _USER, _PASS, _NAME) and
 *     pdo_mysql. Untranslated. Real tables under a random prefix, dropped
 *     afterwards: MySQL cannot reference a TEMPORARY table twice in one
 *     statement, and every predicate here reads postmeta twice.
 */
#[CoversClass(G::class)]
#[CoversClass(PeepSoActivityRepository::class)]
final class GroupFactsAllRowSqlTest extends TestCase
{
    private const POST = 1;
    private const ACT  = 31;

    private ?PDO $pdo = null;
    private string $prefix = '';
    private bool $mysql = false;
    private PdoWpdb $db;
    /** @var list<string> */
    private array $mismatches = [];
    /** @var mixed */
    private $priorWpdb;

    protected function setUp(): void
    {
        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->mysql) {
            foreach (['postmeta', 'posts', 'peepso_activities'] as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . $table);
            }
        }
        $this->pdo = null;
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
    }

    public function testDuplicatesFoldStrictestWinsOnSqlite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            if (getenv('GITHUB_ACTIONS') === 'true') {
                self::fail('pdo_sqlite must be loaded in CI: this test executes the group-facts predicates.');
            }
            self::markTestSkipped('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite).');
        }

        $this->open(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), false, 'gfa_');
        $this->assertEveryCase();
    }

    public function testDuplicatesFoldStrictestWinsOnMysql(): void
    {
        $port = getenv('BCC_TEST_DB_PORT');
        if ($port === false || $port === '') {
            self::markTestSkipped('Opt-in: set BCC_TEST_DB_PORT (and _HOST/_USER/_PASS/_NAME) to run the predicates on MySQL.');
        }
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is not loaded (run with -d extension=pdo_mysql).');
        }

        $this->open(new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
                (int) $port,
                getenv('BCC_TEST_DB_NAME') ?: 'bcc_test'
            ),
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        ), true, 'gfa_' . bin2hex(random_bytes(4)) . '_');
        $this->assertEveryCase();
    }

    // ── the cases ───────────────────────────────────────────────────────

    private function assertEveryCase(): void
    {
        $pm       = $this->prefix . 'postmeta';
        $publicAll = G::publicAllPredicate($pm);
        $nonMember = G::everyRowIn($pm, 'p', 'nmv_pm', G::META_VISIBILITY, ['public_group', 'public_all']);
        $known     = G::sqlPredicate($pm, [7]);
        $unknown   = G::sqlPredicate($pm, null);
        $madeWhile = G::everyRowIn($pm, 'p', 'gsg_at', G::META_PRIVACY_AT_POST, G::NON_OPEN_PRIVACY, true);
        $byId      = $this->getByIdStatement();

        // publicAllPredicate, a post in group 7: [visibility rows, holds].
        foreach ([
            [['public_all'], true],
            [['public_all', 'public_all'], true],
            [['public_all', 'members_only'], false],
            [['public_all', 'public_group'], false],
            [['public_all', null], false],
            [[], false],
            [['members_only'], false],
        ] as [$vis, $holds]) {
            $this->bothOrders($vis, function (array $rows) use ($publicAll, $byId, $holds): void {
                $this->seed(['7'], $rows, []);
                $this->expect('publicAll vis=' . self::show($rows), $holds, $this->holds($publicAll));
                // The single-post read must agree with the predicate.
                $column = $this->column($byId, 'bcc_post_visibility');
                $this->expect('getById vis=' . self::show($rows) . ' → ' . var_export($column, true), $holds, $column === G::VISIBILITY_GLOBAL);
            });
        }
        // Not in a group: always passes, whatever visibility rows exist.
        foreach ([[], ['members_only']] as $vis) {
            $this->seed([], $vis, []);
            $this->expect('publicAll no group vis=' . self::show($vis), true, $this->holds($publicAll));
        }

        // everyRowIn with the non-member values (teaser / parity clause).
        foreach ([
            [['public_group'], true],
            [['public_all', 'public_group'], true],
            [['public_group', 'members_only'], false],
            [[null, 'public_all'], false],
            [[], false],
        ] as [$vis, $holds]) {
            $this->bothOrders($vis, function (array $rows) use ($nonMember, $holds): void {
                $this->seed(['7'], $rows, []);
                $this->expect('nonMember vis=' . self::show($rows), $holds, $this->holds($nonMember));
            });
        }

        // sqlPredicate, a post in group 7 (non-open in the known set, and
        // the unknown set judges every group post the same way).
        foreach ([
            [['1'], true],
            [['2'], true],
            [['1', '2'], true],
            [[' 1'], true],
            [['1 '], true],
            [['1', '0'], false],
            [[' 1', '0'], false],
            [['2', null], false],
            [[], false],
            [['0'], false],
        ] as [$privacy, $holds]) {
            $this->bothOrders($privacy, function (array $rows) use ($known, $unknown, $madeWhile, $byId, $holds): void {
                $this->seed(['7'], [], $rows);
                $this->expect('known [7] privacy=' . self::show($rows), $holds, $this->holds($known));
                $this->expect('unknown privacy=' . self::show($rows), $holds, $this->holds($unknown));
                $this->expect('madeWhileNonOpen privacy=' . self::show($rows), $holds, $this->holds($madeWhile));
                // The single-post read, judged the way ActivityFeedService does.
                $column = $this->column($byId, 'bcc_privacy_at_post');
                $this->expect(
                    'getById privacy=' . self::show($rows) . ' → ' . var_export($column, true),
                    $holds,
                    G::madeWhileNonOpen($column)
                );
            });
        }

        // An OPEN group passes on the known set whatever the record says;
        // a post in an open AND a non-open group is judged on the record.
        foreach ([
            [['8'], ['0'], true],
            [['8'], [], true],
            [['7', '8'], ['0'], false],
            [['7', '8'], ['1'], true],
        ] as [$groups, $privacy, $holds]) {
            $this->seed($groups, [], $privacy);
            $this->expect('known [7] groups=' . self::show($groups) . ' privacy=' . self::show($privacy), $holds, $this->holds($known));
        }
        $this->seed([], [], []);
        $this->expect('unknown, no group', true, $this->holds($unknown));

        self::assertSame([], $this->mismatches, "duplicate group-facts rows did not fold strictest-wins:\n" . implode("\n", $this->mismatches));
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function open(PDO $pdo, bool $mysql, string $prefix): void
    {
        $this->pdo    = $pdo;
        $this->mysql  = $mysql;
        $this->prefix = $prefix;
        $this->db     = new PdoWpdb($pdo, $prefix);
        $GLOBALS['wpdb'] = $this->db;

        $id = $mysql ? 'meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'meta_id INTEGER PRIMARY KEY AUTOINCREMENT';
        $charset = $mysql ? ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $pdo->exec('CREATE TABLE ' . $prefix . 'postmeta (' . $id
            . ', post_id BIGINT NOT NULL, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)' . $charset);
        $pdo->exec('CREATE TABLE ' . $prefix . 'posts (ID BIGINT NOT NULL, post_author BIGINT NOT NULL,'
            . ' post_date_gmt DATETIME NOT NULL, post_status VARCHAR(20) NOT NULL)' . $charset);
        $pdo->exec('CREATE TABLE ' . $prefix . 'peepso_activities (act_id BIGINT NOT NULL, act_owner_id BIGINT NOT NULL,'
            . ' act_module_id INT NOT NULL, act_external_id BIGINT NOT NULL, act_access SMALLINT NULL, act_repost_id BIGINT NULL)' . $charset);
        $pdo->exec('INSERT INTO ' . $prefix . "posts (ID, post_author, post_date_gmt, post_status) VALUES (1, 5, '2026-11-24 00:00:00', 'publish')");
        $pdo->exec('INSERT INTO ' . $prefix . 'peepso_activities (act_id, act_owner_id, act_module_id, act_external_id, act_access, act_repost_id)'
            . ' VALUES (' . self::ACT . ', 5, 1, ' . self::POST . ', NULL, NULL)');
    }

    /**
     * @param list<string>      $groupIds
     * @param list<string|null> $visibility in meta_id order
     * @param list<string|null> $privacy    in meta_id order
     */
    private function seed(array $groupIds, array $visibility, array $privacy): void
    {
        \assert($this->pdo !== null);
        $this->pdo->exec('DELETE FROM ' . $this->prefix . 'postmeta');
        $insert = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)');
        $rows   = [];
        foreach ($groupIds as $g) {
            $rows[] = ['peepso_group_id', $g];
        }
        foreach ($visibility as $v) {
            $rows[] = [G::META_VISIBILITY, $v];
        }
        foreach ($privacy as $v) {
            $rows[] = [G::META_PRIVACY_AT_POST, $v];
        }
        foreach ($rows as [$key, $value]) {
            $insert->bindValue(1, self::POST, PDO::PARAM_INT);
            $insert->bindValue(2, $key);
            $insert->bindValue(3, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->execute();
        }
    }

    /**
     * @param list<string|null>                $rows
     * @param \Closure(list<string|null>): void $run
     */
    private function bothOrders(array $rows, \Closure $run): void
    {
        $run($rows);
        if (count($rows) > 1) {
            $run(array_reverse($rows));
        }
    }

    /** @param array{where: string, params: list<string>} $predicate */
    private function holds(array $predicate): bool
    {
        \assert($this->pdo !== null);
        $sql = $this->db->prepare(
            'SELECT p.ID FROM ' . $this->prefix . 'posts p WHERE p.ID = ' . self::POST . ' AND ' . $predicate['where'],
            ...$predicate['params']
        );

        return array_map('intval', $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)) === [self::POST];
    }

    /** getById()'s real statement, with the group-visibility columns. */
    private function getByIdStatement(): string
    {
        $render           = new RenderingWpdb();
        $render->prefix   = $this->prefix;
        $render->posts    = $this->prefix . 'posts';
        $render->postmeta = $this->prefix . 'postmeta';
        $GLOBALS['wpdb']  = $render;
        PeepSoActivityRepository::getById(self::ACT, null, withGroupVisibility: true);
        $GLOBALS['wpdb'] = $this->db;

        $sql = trim($render->lastSql);
        self::assertStringContainsString('AS bcc_privacy_at_post', $sql);
        self::assertStringNotContainsString('wp_', $sql, 'every table must be the test copy');
        if (!$this->mysql) {
            $sql = (string) preg_replace('/GROUP_CONCAT\((\w+\.meta_value) ORDER BY \w+\.meta_id SEPARATOR \',\'\)/', 'GROUP_CONCAT($1, \',\')', $sql, -1, $n);
            self::assertSame(1, $n, 'expected one GROUP_CONCAT to rewrite');
        }

        return $sql;
    }

    private function column(string $statement, string $name): ?string
    {
        \assert($this->pdo !== null);
        $row = $this->pdo->query($statement)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'getById found its row');
        $value = $row[$name] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function expect(string $what, bool $expected, bool $actual): void
    {
        if ($expected !== $actual) {
            $this->mismatches[] = sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true));
        }
    }

    /** @param list<string|null> $values */
    private static function show(array $values): string
    {
        return '[' . implode(',', array_map(static fn(?string $v): string => $v === null ? 'NULL' : "'" . $v . "'", $values)) . ']';
    }
}
