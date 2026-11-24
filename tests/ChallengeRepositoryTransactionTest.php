<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Wallet\ChallengeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * ChallengeRepository's in-transaction primitives (design §4.4, A2):
 *   - transactionState(): the SAVEPOINT / RELEASE probe;
 *   - consumeInTransaction(): the use-once step inside the caller's
 *     transaction, refused outside one;
 *   - update(): a compare-and-set in its own transaction, refused inside
 *     one.
 * Over an in-memory wp_options table with transaction semantics
 * (tests/Stubs/challenge-tx-wpdb.php).
 */
#[CoversClass(ChallengeRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChallengeRepositoryTransactionTest extends TestCase
{
    private \BccTxOptionsWpdb $db;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/challenge-tx-wpdb.php';
        $this->db        = new \BccTxOptionsWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    private function seed(string $key, int $expiresIn = 600): void
    {
        $this->db->rows['_transient_' . $key]         = serialize(['nonce' => 'n-' . $key, 'expires_at' => time() + $expiresIn]);
        $this->db->rows['_transient_timeout_' . $key] = (string) (time() + $expiresIn);
    }

    // ── transactionState() ──────────────────────────────────────────────

    public function testTheProbeTellsInsideFromOutside(): void
    {
        self::assertSame(ChallengeRepository::TX_OUTSIDE, ChallengeRepository::transactionState());

        $this->db->query('START TRANSACTION');
        self::assertSame(ChallengeRepository::TX_INSIDE, ChallengeRepository::transactionState());
        self::assertTrue($this->db->inTransaction, 'the probe leaves the transaction open');
        $this->db->query('ROLLBACK');

        self::assertSame(ChallengeRepository::TX_OUTSIDE, ChallengeRepository::transactionState());
    }

    /** Only the 1305 message means "outside"; anything else cannot tell. */
    public function testAnyOtherProbeFailureIsUnknown(): void
    {
        $this->db->faults = ['release_other_error' => true];
        self::assertSame(ChallengeRepository::TX_UNKNOWN, ChallengeRepository::transactionState());

        $this->db->faults = ['savepoint_fails' => true];
        self::assertSame(ChallengeRepository::TX_UNKNOWN, ChallengeRepository::transactionState());
    }

    public function testEachProbeUsesAFreshSavepointAndLeavesNoErrorBehind(): void
    {
        ChallengeRepository::transactionState();
        ChallengeRepository::transactionState();

        $names = [];
        foreach ($this->db->log as $sql) {
            if (str_starts_with($sql, 'SAVEPOINT ')) {
                $names[] = substr($sql, 10);
            }
        }
        self::assertCount(2, $names);
        self::assertNotSame($names[0], $names[1], 'a caller\'s savepoint of the same name would be replaced');
        self::assertMatchesRegularExpression('/\Abcc_txprobe_[0-9a-f]{16}\z/', $names[0]);
        self::assertSame('', $this->db->last_error, 'the expected 1305 is cleared');
        self::assertFalse($this->db->suppress, 'error suppression is restored');
    }

    // ── consumeInTransaction() ──────────────────────────────────────────

    public function testConsumeInTransactionIsRefusedOutsideATransaction(): void
    {
        $this->seed('k');
        try {
            ChallengeRepository::consumeInTransaction('k');
            self::fail('consumed outside a transaction');
        } catch (\LogicException $e) {
            self::assertArrayHasKey('_transient_k', $this->db->rows, 'nothing was deleted');
        }
    }

    public function testConsumeInTransactionIsRefusedWhenTheProbeCannotTell(): void
    {
        $this->seed('k');
        $this->db->query('START TRANSACTION');
        $this->db->faults = ['release_other_error' => true];

        $this->expectException(\LogicException::class);
        ChallengeRepository::consumeInTransaction('k');
    }

    public function testInsideATransactionItTakesTheChallengeAndLeavesTheCommitToTheCaller(): void
    {
        $this->seed('k');
        $this->db->query('START TRANSACTION');
        $logFrom = count($this->db->log);

        $challenge = ChallengeRepository::consumeInTransaction('k');

        self::assertSame('n-k', $challenge['nonce'] ?? null);
        self::assertArrayNotHasKey('_transient_k', $this->db->rows);
        self::assertArrayNotHasKey('_transient_timeout_k', $this->db->rows);
        self::assertTrue($this->db->inTransaction, 'not committed here');
        $mine = array_slice($this->db->log, $logFrom);
        self::assertNotContains('COMMIT', $mine);
        self::assertNotContains('ROLLBACK', $mine);

        $this->db->query('ROLLBACK');
        self::assertArrayHasKey('_transient_k', $this->db->rows, 'the caller\'s rollback restores it');
    }

    public function testAMissingOrExpiredChallengeIsNullAndNothingIsDeleted(): void
    {
        $this->seed('old', -5);
        $this->db->query('START TRANSACTION');

        self::assertNull(ChallengeRepository::consumeInTransaction('missing'));
        self::assertNull(ChallengeRepository::consumeInTransaction('old'));
        self::assertArrayHasKey('_transient_old', $this->db->rows);
    }

    public function testAFailedReadThrowsRatherThanReadingAsMissing(): void
    {
        $this->seed('k');
        $this->db->query('START TRANSACTION');
        $this->db->faults = ['read_fails' => true];

        $this->expectException(\RuntimeException::class);
        ChallengeRepository::consumeInTransaction('k');
    }

    /** wpdb's pre-flush bail: query() false, no last_error, the PREVIOUS rows still in last_result. */
    public function testAStaleResultIsNeverTakenForTheRow(): void
    {
        $this->seed('k');
        $this->db->query('START TRANSACTION');
        $this->db->last_result = [(object) ['option_value' => serialize(['nonce' => 'someone else', 'expires_at' => time() + 600])]];
        $this->db->faults      = ['pre_flush_bail' => true];

        $this->expectException(\RuntimeException::class);
        ChallengeRepository::consumeInTransaction('k');
    }

    public function testADeleteThatDoesNotRemoveExactlyOneRowThrows(): void
    {
        $this->seed('k');
        $this->db->query('START TRANSACTION');
        $this->db->faults = ['delete_zero' => true];

        $this->expectException(\RuntimeException::class);
        ChallengeRepository::consumeInTransaction('k');
    }

    // ── update() ────────────────────────────────────────────────────────

    public function testUpdateWritesTheChangeInItsOwnTransaction(): void
    {
        $this->seed('k');

        $out = ChallengeRepository::update('k', static function (array $c): array {
            $c['reserved'] = 'yes';
            return $c;
        });

        self::assertSame('yes', $out['reserved'] ?? null);
        self::assertSame('yes', unserialize($this->db->rows['_transient_k'])['reserved'] ?? null);
        self::assertContains('COMMIT', $this->db->log);
        self::assertFalse($this->db->inTransaction);
    }

    public function testARefusedChangeWritesNothing(): void
    {
        $this->seed('k');
        $before = $this->db->rows['_transient_k'];

        self::assertNull(ChallengeRepository::update('k', static fn (array $c): ?array => null));
        self::assertSame($before, $this->db->rows['_transient_k']);
        self::assertContains('ROLLBACK', $this->db->log);
        self::assertNotContains('COMMIT', $this->db->log);
        self::assertSame([], array_values(array_filter($this->db->log, static fn (string $q): bool => str_starts_with($q, 'UPDATE'))), 'not even an UPDATE is sent');
    }

    public function testUpdateOfAMissingOrExpiredChallengeIsNull(): void
    {
        $this->seed('old', -5);
        $called = false;
        $change = static function (array $c) use (&$called): array {
            $called = true;
            return $c;
        };

        self::assertNull(ChallengeRepository::update('missing', $change));
        self::assertNull(ChallengeRepository::update('old', $change));
        self::assertFalse($called, 'the change never sees a dead challenge');
    }

    public function testAFailedWriteOrReadIsNullAndRolledBack(): void
    {
        $this->seed('k');
        $this->db->faults = ['update_fails' => true];
        self::assertNull(ChallengeRepository::update('k', static fn (array $c): array => $c + ['x' => 1]));
        self::assertNotContains('COMMIT', $this->db->log);

        $this->db->faults = ['read_fails' => true];
        self::assertNull(ChallengeRepository::update('k', static fn (array $c): array => $c));
    }

    public function testUpdateIsRefusedInsideATransactionOrWhenTheProbeCannotTell(): void
    {
        $this->seed('k');
        $this->db->query('START TRANSACTION');
        try {
            ChallengeRepository::update('k', static fn (array $c): array => $c);
            self::fail('START TRANSACTION would have committed the caller\'s work');
        } catch (\LogicException $e) {
            self::assertTrue($this->db->inTransaction, 'the caller\'s transaction is untouched');
        }
        $this->db->query('ROLLBACK');

        $this->db->faults = ['release_other_error' => true];
        $this->expectException(\LogicException::class);
        ChallengeRepository::update('k', static fn (array $c): array => $c);
    }

    public function testAnExceptionFromTheChangeRollsBack(): void
    {
        $this->seed('k');
        try {
            ChallengeRepository::update('k', static function (array $c): array {
                throw new \DomainException('boom');
            });
            self::fail('swallowed');
        } catch (\DomainException $e) {
            self::assertFalse($this->db->inTransaction);
            self::assertContains('ROLLBACK', $this->db->log);
        }
    }
}
