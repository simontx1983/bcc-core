<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\DB\TransactionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * DB\TransactionState::current(), the shared SAVEPOINT / RELEASE probe: is a
 * transaction open on this connection?
 *
 * Over the $wpdb double with transaction semantics in
 * tests/Stubs/challenge-tx-wpdb.php (SAVEPOINT outside a transaction leaves
 * nothing behind, so RELEASE fails with 1305). The same answers on a real
 * MySQL 8 and MariaDB server: tests/manual/challenge-tx-probe-realdb.php.
 */
#[CoversClass(TransactionState::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TransactionStateTest extends TestCase
{
    private \BccTxOptionsWpdb $db;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/challenge-tx-wpdb.php';
        $this->db                    = new \BccTxOptionsWpdb();
        $GLOBALS['wpdb']             = $this->db;
        $GLOBALS['__bcc_transients'] = [];
        unset($GLOBALS['__bcc_errno']);
    }

    /** A wpdb mysqli link the probe can read an error number from. */
    private function withConnection(int $errno): void
    {
        $this->db->dbh          = (new \ReflectionClass(\mysqli::class))->newInstanceWithoutConstructor();
        $GLOBALS['__bcc_errno'] = $errno;
    }

    public function testTheProbeTellsInsideFromOutside(): void
    {
        self::assertSame(TransactionState::OUTSIDE, TransactionState::current());

        $this->db->query('START TRANSACTION');
        self::assertSame(TransactionState::INSIDE, TransactionState::current());
        self::assertTrue($this->db->inTransaction, 'the probe leaves the transaction open');
        $this->db->query('ROLLBACK');

        self::assertSame(TransactionState::OUTSIDE, TransactionState::current());
    }

    /** Only the 1305 answer means "outside"; anything else cannot tell. */
    public function testAnyOtherProbeFailureIsUnknown(): void
    {
        $this->db->faults = ['release_other_error' => true];
        self::assertSame(TransactionState::UNKNOWN, TransactionState::current());

        $this->db->faults = ['savepoint_fails' => true];
        self::assertSame(TransactionState::UNKNOWN, TransactionState::current());
    }

    public function testEachProbeUsesAFreshSavepointAndLeavesNoErrorBehind(): void
    {
        TransactionState::current();
        TransactionState::current();

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

    /** The expected 1305 must not reach the error log: both probe statements run with errors suppressed. */
    public function testTheProbeRunsWithErrorsSuppressed(): void
    {
        TransactionState::current();

        self::assertSame(['SAVEPOINT', 'RELEASE'], array_map(static fn (string $sql): string => strtok($sql, ' '), $this->db->log));
        self::assertSame([true, true], $this->db->suppressedLog);
    }

    /** A failed probe still clears its error and restores suppression. */
    public function testAFailedProbeLeavesNoErrorBehindEither(): void
    {
        $this->db->faults = ['release_other_error' => true];
        TransactionState::current();

        self::assertSame('', $this->db->last_error);
        self::assertFalse($this->db->suppress);
    }

    // ── the error NUMBER decides (security L2) ──────────────────────────

    /** A server answering in another language (lc_messages) is still "outside" on error 1305. */
    public function testOutsideIsRecognisedByErrorNumberInAnyLanguage(): void
    {
        $this->db->localisedMessages = true;
        $this->withConnection(1305);

        self::assertSame(TransactionState::OUTSIDE, TransactionState::current());
    }

    /** Another error number is unknown, whatever the message says. */
    public function testAnotherErrorNumberIsUnknownEvenWithTheEnglishMessage(): void
    {
        $this->withConnection(2013); // CR_SERVER_LOST

        self::assertSame(TransactionState::UNKNOWN, TransactionState::current());
    }

    /** Only with no error number to read does the English message decide; a localised one cannot. */
    public function testWithoutAConnectionTheEnglishMessageIsTheFallback(): void
    {
        self::assertSame(TransactionState::OUTSIDE, TransactionState::current());

        $this->db->localisedMessages = true;
        self::assertSame(TransactionState::UNKNOWN, TransactionState::current());
    }

    /** Callers record under their own subsystem; the probe itself records nothing. */
    public function testTheProbeRecordsNoMetricOfItsOwn(): void
    {
        $this->db->faults = ['release_other_error' => true];
        self::assertSame(TransactionState::UNKNOWN, TransactionState::current());

        self::assertSame(0, \BCC\Core\Observability\DegradationMetrics::readEvent('wallet_challenge', 'tx_probe_unknown', time()));
        self::assertSame(0, \BCC\Core\Observability\DegradationMetrics::readEvent('db_transaction', 'probe_unknown', time()));
    }
}
