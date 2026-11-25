<?php

declare(strict_types=1);

namespace BCC\Core\Repositories\Tests\PostMetaWrite;

use BCC\Core\Feed\GroupSyndicationGate as G;
use BCC\Core\PeepSo\PeepSoStatusWriter;
use BCC\Core\Repositories\PostMetaRepository;
use BCC\Core\Tests\Stubs\PdoWpdb;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * PostMetaRepository::replaceWithOneRow(), the single-row writer for the
 * group-facts keys, and PeepSoStatusWriter::attachToGroup() on top of it.
 *
 * Executed on SQLite (always; ⚠ FAILS, not skips, in GitHub Actions without
 * pdo_sqlite) and MySQL (opt-in, BCC_TEST_DB_PORT; real tables under a
 * random prefix, dropped afterwards). The post-meta API is modelled with a
 * per-request cache (tests/Stubs/postmeta-write-stubs.php), so a writer
 * that leaves the cache stale is visible.
 */
#[CoversClass(PostMetaRepository::class)]
#[CoversClass(PeepSoStatusWriter::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PostMetaRepositoryWriteTest extends TestCase
{
    private const POST  = 500;
    private const GROUP = 40;

    private ?PDO $pdo = null;
    private string $prefix = '';
    private bool $mysql = false;
    private PdoWpdb $db;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/pdo-wpdb.php';
        require_once __DIR__ . '/Stubs/postmeta-write-stubs.php';
        $GLOBALS['__pmw_deletes'] = [];
        $GLOBALS['__pmw_actions'] = [];
        $GLOBALS['__pmw_cache']   = [];
        $GLOBALS['__pmw_log']     = [];
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->mysql) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->prefix . 'postmeta');
        }
        $this->pdo = null;
    }

    // ── the writer ──────────────────────────────────────────────────────

    public function testItLeavesExactlyOneRowWhateverWasThere(): void
    {
        $this->sqlite();
        $this->seed([[self::POST, 'k', 'a'], [self::POST, 'other', 'x'], [self::POST, 'k', 'b'], [7, 'k', 'z']]);

        self::assertTrue(PostMetaRepository::replaceWithOneRow(self::POST, 'k', 'c'));

        self::assertSame(['c'], $this->values(self::POST, 'k'));
        self::assertSame(['x'], $this->values(self::POST, 'other'), 'another key is untouched');
        self::assertSame(['z'], $this->values(7, 'k'), 'another post is untouched');
        self::assertSame([[self::POST, 'post_meta']], $GLOBALS['__pmw_deletes'], 'the post\'s meta cache is dropped');
    }

    /** No read before the write, so a read that fails as empty can never turn into an extra row. */
    public function testItNeverReadsFirstInsertsThenDeletes(): void
    {
        $this->sqlite();
        $this->seed([[self::POST, 'k', 'a']]);

        PostMetaRepository::replaceWithOneRow(self::POST, 'k', 'b');

        self::assertCount(2, $this->db->log);
        self::assertStringStartsWith('INSERT INTO ' . $this->prefix . 'postmeta', $this->db->log[0]);
        self::assertStringStartsWith('DELETE FROM ' . $this->prefix . 'postmeta', $this->db->log[1]);
    }

    public function testAFailedInsertChangesNothingAndDeletesNothing(): void
    {
        $this->sqlite();
        $this->seed([[self::POST, 'k', 'a'], [self::POST, 'k', 'b']]);
        $this->db->failOn = '/^INSERT/';

        self::assertFalse(PostMetaRepository::replaceWithOneRow(self::POST, 'k', 'c'));

        self::assertSame(['a', 'b'], $this->values(self::POST, 'k'));
        self::assertCount(1, $this->db->log, 'no DELETE after a failed INSERT');
        self::assertSame([], $GLOBALS['__pmw_deletes'], 'nothing was written, so the cache stays');
    }

    /** An insert with no id is a failure: without its id the delete has no bound. */
    public function testAnInsertWithNoIdIsAFailureAndDeletesNothing(): void
    {
        $this->sqlite();
        $this->seed([[self::POST, 'k', 'a']]);
        $this->db->insertIdOverride = 0;

        self::assertFalse(PostMetaRepository::replaceWithOneRow(self::POST, 'k', 'c'));

        self::assertSame(['a', 'c'], $this->values(self::POST, 'k'), 'the old row is kept beside the new one');
        self::assertCount(1, $this->db->log, 'no DELETE');
        self::assertSame([[self::POST, 'post_meta']], $GLOBALS['__pmw_deletes'], 'a row was written, so the cache is dropped');
    }

    /** A failed delete is reported, and leaves the old rows BESIDE the new one: never zero rows. */
    public function testAFailedDeleteIsReportedAndLeavesNoGap(): void
    {
        $this->sqlite();
        $this->seed([[self::POST, 'k', 'a'], [self::POST, 'k', 'b']]);
        $this->db->failOn = '/^DELETE/';

        self::assertFalse(PostMetaRepository::replaceWithOneRow(self::POST, 'k', 'c'));

        self::assertSame(['a', 'b', 'c'], $this->values(self::POST, 'k'));
        self::assertSame([[self::POST, 'post_meta']], $GLOBALS['__pmw_deletes']);
    }

    /**
     * Two writers at once, interleaved as insert A, insert B, delete A,
     * delete B (each in its own Fiber, suspended just before its DELETE):
     * each deletes only rows OLDER than its own, so exactly one row is left,
     * the newest. Deleting "every other row" would leave none.
     */
    public function testTwoInterleavedWritersLeaveExactlyTheNewestRow(): void
    {
        $this->sqlite();
        $this->seed([[self::POST, 'k', 'old']]);
        $this->db->beforeQuery = static function (string $sql): void {
            if (str_starts_with($sql, 'DELETE') && \Fiber::getCurrent() !== null) {
                \Fiber::suspend();
            }
        };
        $a = new \Fiber(static fn (): bool => PostMetaRepository::replaceWithOneRow(self::POST, 'k', 'A'));
        $b = new \Fiber(static fn (): bool => PostMetaRepository::replaceWithOneRow(self::POST, 'k', 'B'));

        $a->start();  // insert A, then suspends before its delete
        $b->start();  // insert B, then suspends before its delete
        $a->resume(); // delete A
        $b->resume(); // delete B

        self::assertTrue($a->isTerminated() && $b->isTerminated());
        self::assertTrue($a->getReturn());
        self::assertTrue($b->getReturn());
        self::assertSame(['INSERT', 'INSERT', 'DELETE', 'DELETE'], array_map(static fn (string $s): string => strtok($s, ' '), $this->db->log), 'the interleaving happened');
        self::assertSame(['B'], $this->values(self::POST, 'k'));
    }

    public function testUnusableInputWritesNothing(): void
    {
        $this->sqlite();

        self::assertFalse(PostMetaRepository::replaceWithOneRow(0, 'k', 'v'));
        self::assertFalse(PostMetaRepository::replaceWithOneRow(-1, 'k', 'v'));
        self::assertFalse(PostMetaRepository::replaceWithOneRow(self::POST, '', 'v'));
        self::assertFalse(PostMetaRepository::replaceWithOneRow(self::POST, str_repeat('k', 256), 'v'));
        self::assertSame([], $this->db->log);
    }

    // ── attachToGroup() on top of it ────────────────────────────────────

    /**
     * Each group-facts key ends with exactly one row, even when duplicates
     * pre-exist, and a get_post_meta() in the same request (after an earlier
     * read cached the post) sees exactly that one row.
     */
    public function testAttachToGroupLeavesOneRowPerKeyAndAFreshCache(): void
    {
        $this->sqlite();
        $this->assertAttachOnThisEngine();
    }

    public function testAReattachReplacesRatherThanAppends(): void
    {
        $this->sqlite();
        $this->seed([[self::GROUP, 'peepso_group_privacy', '0']]);

        self::assertTrue(PeepSoStatusWriter::attachToGroup(self::POST, self::GROUP, 'members_only'));
        self::assertTrue(PeepSoStatusWriter::attachToGroup(self::POST, self::GROUP, 'public_all'));

        self::assertSame([(string) self::GROUP], $this->values(self::POST, 'peepso_group_id'));
        self::assertSame(['public_all'], $this->values(self::POST, G::META_VISIBILITY));
        self::assertSame(['0'], $this->values(self::POST, G::META_PRIVACY_AT_POST));
    }

    public function testAKeyThatCannotBeWrittenIsReportedAndTheOthersAreStillWritten(): void
    {
        $this->sqlite();
        $this->seed([[self::GROUP, 'peepso_group_privacy', '1']]);
        $this->db->failOn = "/^INSERT .* VALUES \\(500, '_bcc_post_visibility'/";

        self::assertFalse(PeepSoStatusWriter::attachToGroup(self::POST, self::GROUP, 'public_all'));

        self::assertSame([(string) self::GROUP], $this->values(self::POST, 'peepso_group_id'));
        self::assertSame([], $this->values(self::POST, G::META_VISIBILITY), 'absent: read as members_only');
        self::assertSame(['1'], $this->values(self::POST, G::META_PRIVACY_AT_POST));
        self::assertSame('peepso_groups_new_post', $GLOBALS['__pmw_actions'][0][0] ?? null, 'the hook still fires, as before');
        self::assertCount(1, $GLOBALS['__pmw_log']);
        self::assertSame('ERROR', $GLOBALS['__pmw_log'][0][0]);
        self::assertSame([G::META_VISIBILITY], $GLOBALS['__pmw_log'][0][2]['keys'] ?? null, 'the failed key is named');
    }

    public function testTheWriterExecutesOnMysql(): void
    {
        $port = getenv('BCC_TEST_DB_PORT');
        if ($port === false || $port === '') {
            self::markTestSkipped('Opt-in: set BCC_TEST_DB_PORT (and _HOST/_USER/_PASS/_NAME) to run the writer on MySQL.');
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
        ), true, 'pmw_' . bin2hex(random_bytes(4)) . '_');

        $this->assertAttachOnThisEngine();
    }

    private function assertAttachOnThisEngine(): void
    {
        $this->seed([
            [self::GROUP, 'peepso_group_privacy', '1'],
            [self::POST, 'peepso_group_id', (string) self::GROUP],
            [self::POST, G::META_VISIBILITY, 'public_all'],
            [self::POST, 'peepso_group_id', '41'],
            [self::POST, G::META_VISIBILITY, 'members_only'],
        ]);
        self::assertSame([(string) self::GROUP, '41'], get_post_meta(self::POST, 'peepso_group_id'), 'control: the duplicates are there, and now cached');

        self::assertTrue(PeepSoStatusWriter::attachToGroup(self::POST, self::GROUP, 'members_only'));

        self::assertSame([(string) self::GROUP], $this->values(self::POST, 'peepso_group_id'));
        self::assertSame(['members_only'], $this->values(self::POST, G::META_VISIBILITY));
        self::assertSame(['1'], $this->values(self::POST, G::META_PRIVACY_AT_POST));

        // The same request reads exactly one row per key, not the cached duplicates.
        self::assertSame([(string) self::GROUP], get_post_meta(self::POST, 'peepso_group_id'));
        self::assertSame(['members_only'], get_post_meta(self::POST, G::META_VISIBILITY));
        self::assertSame(['1'], get_post_meta(self::POST, G::META_PRIVACY_AT_POST));
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function sqlite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            if (getenv('GITHUB_ACTIONS') === 'true') {
                self::fail('pdo_sqlite must be loaded in CI: this test executes the post-meta writer.');
            }
            self::markTestSkipped('pdo_sqlite is not loaded (run with -d extension=pdo_sqlite).');
        }
        $this->open(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), false, 'pmw_');
    }

    private function open(PDO $pdo, bool $mysql, string $prefix): void
    {
        $this->pdo    = $pdo;
        $this->mysql  = $mysql;
        $this->prefix = $prefix;
        $pdo->exec('CREATE TABLE ' . $prefix . 'postmeta ('
            . ($mysql ? 'meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'meta_id INTEGER PRIMARY KEY AUTOINCREMENT')
            . ', post_id BIGINT NOT NULL, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL)'
            . ($mysql ? ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
        $this->db        = new PdoWpdb($pdo, $prefix);
        $GLOBALS['wpdb'] = $this->db;
    }

    /** @param list<array{int, string, string}> $rows in meta_id order */
    private function seed(array $rows): void
    {
        \assert($this->pdo !== null);
        $insert = $this->pdo->prepare('INSERT INTO ' . $this->prefix . 'postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)');
        foreach ($rows as $row) {
            $insert->execute($row);
        }
        $this->db->log = [];
    }

    /** @return list<string> the key's values on the post, in meta_id order, read straight from the table */
    private function values(int $postId, string $key): array
    {
        \assert($this->pdo !== null);
        $read = $this->pdo->prepare('SELECT meta_value FROM ' . $this->prefix . 'postmeta WHERE post_id = ? AND meta_key = ? ORDER BY meta_id');
        $read->execute([$postId, $key]);

        return array_map('strval', $read->fetchAll(PDO::FETCH_COLUMN));
    }
}
