<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Crypto\Bech32;
use BCC\Core\DB\AdvisoryLock;
use BCC\Core\Log\Logger;
use BCC\Core\Observability\DegradationMetrics;
use BCC\Core\Wallet\ValidatorMemoChallenge as C;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The validator memo claim's challenge (design §4.1, §4.2, A2): the memo
 * and its code, the window, the per-member limits, the per-valoper signal
 * and backstop (security's ruling, 2026-11-24), reserve, withdraw and the
 * in-transaction consume. Over an in-memory wp_options table with
 * transaction semantics (tests/Stubs/challenge-tx-wpdb.php).
 */
#[CoversClass(C::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorMemoChallengeTest extends TestCase
{
    private const HOST = 'bluecollarcrypto.test';
    private const HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789';

    private \BccTxOptionsWpdb $db;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Stubs/challenge-tx-wpdb.php';
        if (!defined('BCC_FRONTEND_ORIGIN')) {
            define('BCC_FRONTEND_ORIGIN', 'https://' . self::HOST);
        }
        $this->db                     = new \BccTxOptionsWpdb();
        $GLOBALS['wpdb']              = $this->db;
        $GLOBALS['__bcc_transients']  = [];
        AdvisoryLock::$log            = [];
        AdvisoryLock::$deny           = false;
        Logger::$log                  = [];
    }

    private static function valoper(int $n): string
    {
        return Bech32::encode('cosmosvaloper', str_repeat(chr($n), 20));
    }

    /** @return array<string, mixed> */
    private static function issued(int $user, int $valoper, string $handle = 'operator-one'): array
    {
        $r = C::issue($user, $handle, self::valoper($valoper));
        self::assertTrue($r['ok'], 'refused: ' . $r['reason']);
        self::assertIsArray($r['challenge']);
        return $r['challenge'];
    }

    // ── the memo ────────────────────────────────────────────────────────

    public function testTheCodeIsEightSymbolsFromTheAlphabetWithoutLookAlikes(): void
    {
        $seen = [];
        for ($i = 0; $i < 600; $i++) {
            $code = C::code();
            self::assertTrue(C::isCode($code), $code);
            foreach (str_split($code) as $ch) {
                $seen[$ch] = true;
            }
        }
        $symbols = array_map('strval', array_keys($seen)); // PHP turns '2'…'9' keys into ints
        sort($symbols, SORT_STRING);
        self::assertSame(str_split(C::CODE_ALPHABET), $symbols, 'every symbol is drawn, and no other');
        self::assertSame(32, strlen(C::CODE_ALPHABET));
        foreach (['0', 'O', '1', 'I', 'l', 'o', 'i'] as $lookAlike) {
            self::assertStringNotContainsString($lookAlike, C::CODE_ALPHABET);
        }
        self::assertFalse(C::isCode('ABCDEFG'), 'seven');
        self::assertFalse(C::isCode('ABCDEFG0'), 'a look-alike');
        self::assertFalse(C::isCode('abcdefgh'), 'lowercase');
    }

    public function testTheMemoIsTheDesignsSentenceInCanonicalForm(): void
    {
        $valoper = self::valoper(7);
        $memo    = C::memo(strtoupper($valoper), 'operator-one', self::HOST, 'ABCD2345');

        self::assertSame('BCC: claim validator page ' . $valoper . ' for @operator-one on ' . self::HOST . '. Code ABCD2345', $memo);
        self::assertMatchesRegularExpression('/\A[\x20-\x7e]+\z/', (string) $memo, 'printable ASCII');
        self::assertLessThanOrEqual(C::MEMO_MAX_BYTES, strlen((string) $memo));
        self::assertNotNull(C::memo($valoper, 'a-b', 'localhost:10003', 'ABCD2345'), 'a host with a port');
    }

    public function testAMemoIsNotMadeFromPartsOfTheWrongForm(): void
    {
        $v = self::valoper(7);
        self::assertNull(C::memo($v, 'Operator', self::HOST, 'ABCD2345'), 'uppercase handle');
        self::assertNull(C::memo($v, 'op', self::HOST, 'ABCD2345'), 'handle too short');
        self::assertNull(C::memo($v, str_repeat('a', 21), self::HOST, 'ABCD2345'), 'handle too long');
        self::assertNull(C::memo($v, 'op_one', self::HOST, 'ABCD2345'), 'underscore');
        self::assertNull(C::memo($v, 'operator', 'Host.Test', 'ABCD2345'), 'host case');
        self::assertNull(C::memo($v, 'operator', '', 'ABCD2345'), 'no host');
        self::assertNull(C::memo($v, 'operator', self::HOST, 'ABCD234O'), 'a code look-alike');
        self::assertNull(C::memo(Bech32::encode('cosmosvaloper', str_repeat("\x01", 32)), 'operator', self::HOST, 'ABCD2345'), 'not 20 bytes');
        self::assertNull(C::memo(substr($v, 0, -1) . 'q', 'operator', self::HOST, 'ABCD2345'), 'bad checksum');
        self::assertNull(C::memo($v, 'operator', str_repeat('h', 200) . '.test', 'ABCD2345'), 'over 256 bytes');
    }

    public function testTheMemoMatchesByteForByteAfterOuterWhitespaceOnly(): void
    {
        $memo = (string) C::memo(self::valoper(7), 'operator-one', self::HOST, 'ABCD2345');

        self::assertTrue(C::memoMatches($memo, $memo));
        self::assertTrue(C::memoMatches($memo, "  {$memo}\n"));
        self::assertFalse(C::memoMatches($memo, str_replace('ABCD2345', 'ABCD2346', $memo)), 'another code');
        self::assertFalse(C::memoMatches($memo, strtolower($memo)), 'case');
        self::assertFalse(C::memoMatches($memo, str_replace(' for ', '  for ', $memo)), 'inner whitespace');
        self::assertFalse(C::memoMatches($memo, str_replace('@', "\u{FF20}", $memo)), 'a Unicode look-alike');
        self::assertFalse(C::memoMatches('', ''), 'nothing issued');
    }

    public function testTheWindowIsIssueMinusSkewToIssuePlusLifetime(): void
    {
        $c = ['issued_at' => 1_000_000];
        self::assertTrue(C::blockTimeInWindow($c, 1_000_000 - C::CLOCK_SKEW));
        self::assertFalse(C::blockTimeInWindow($c, 1_000_000 - C::CLOCK_SKEW - 1));
        self::assertTrue(C::blockTimeInWindow($c, 1_000_000 + C::LIFETIME));
        self::assertFalse(C::blockTimeInWindow($c, 1_000_000 + C::LIFETIME + 1));
        self::assertFalse(C::blockTimeInWindow([], 1_000_000));
    }

    // ── issue ───────────────────────────────────────────────────────────

    public function testIssueStoresTheChallenge(): void
    {
        $c = self::issued(11, 7);

        self::assertSame(C::PURPOSE, $c['purpose']);
        self::assertSame(11, $c['user_id']);
        self::assertSame('operator-one', $c['handle']);
        self::assertSame(self::valoper(7), $c['valoper']);
        self::assertTrue(C::isCode((string) $c['code']));
        self::assertSame(C::memo(self::valoper(7), 'operator-one', self::HOST, (string) $c['code']), $c['memo']);
        self::assertSame($c['issued_at'] + C::LIFETIME, $c['window_ends_at']);
        self::assertSame($c['window_ends_at'] + C::VERIFY_GRACE, $c['expires_at']);
        self::assertSame('', $c['reserved_hash']);
        self::assertSame($c, C::peek((string) $c['id']));
        self::assertContains('acquire:bcc_vmc_issue', AdvisoryLock::$log);
        self::assertContains('release:bcc_vmc_issue', AdvisoryLock::$log);
    }

    public function testIssueRefusesBadInputAndABusyLock(): void
    {
        self::assertSame(C::REFUSED_BAD_INPUT, C::issue(0, 'operator', self::valoper(7))['reason']);
        self::assertSame(C::REFUSED_BAD_INPUT, C::issue(11, 'Operator', self::valoper(7))['reason']);
        self::assertSame(C::REFUSED_BAD_INPUT, C::issue(11, 'operator', 'cosmosvaloper1notbech32')['reason']);

        AdvisoryLock::$deny = true;
        self::assertSame(C::REFUSED_BUSY, C::issue(11, 'operator', self::valoper(7))['reason']);
        self::assertSame([], $this->db->rows, 'nothing stored');
    }

    /** One live challenge per (member, valoper): re-issuing replaces the member's own, and only theirs. */
    public function testReissuingReplacesOnlyTheMembersOwnChallenge(): void
    {
        $first  = self::issued(11, 7);
        $other  = self::issued(22, 7, 'someone-else');
        $second = self::issued(11, 7);

        self::assertNotSame($first['id'], $second['id']);
        self::assertNotSame($first['code'], $second['code']);
        self::assertNull(C::reserve((string) $first['id'], 11, 'operator-one', self::HASH), 'the replaced one is dead');
        self::assertNotNull(C::reserve((string) $second['id'], 11, 'operator-one', self::HASH));
        self::assertNotNull(C::reserve((string) $other['id'], 22, 'someone-else', self::HASH), 'another member\'s is untouched');
    }

    public function testAChallengeWhoseHashIsBeingVerifiedIsNotReplaced(): void
    {
        $first = self::issued(11, 7);
        self::assertNotNull(C::reserve((string) $first['id'], 11, 'operator-one', self::HASH));

        $again = C::issue(11, 'operator-one', self::valoper(7));
        self::assertFalse($again['ok']);
        self::assertSame(C::REFUSED_IN_VERIFICATION, $again['reason']);
        self::assertSame($first['id'], $again['existing_id']);

        self::assertTrue(C::withdraw((string) $first['id'], 11));
        self::assertTrue(C::issue(11, 'operator-one', self::valoper(7))['ok'], 'after a withdrawal');
    }

    public function testAMemberHoldsAtMostThreeLiveChallenges(): void
    {
        self::issued(11, 1);
        self::issued(11, 2);
        self::issued(11, 3);

        self::assertSame(C::REFUSED_MEMBER_LIVE, C::issue(11, 'operator-one', self::valoper(4))['reason']);
        self::assertTrue(C::issue(11, 'operator-one', self::valoper(3))['ok'], 'renewing one of the three');
        self::assertTrue(C::issue(22, 'operator-two', self::valoper(4))['ok'], 'another member');
    }

    public function testAWithdrawnChallengeStopsCountingTowardTheLiveLimit(): void
    {
        self::issued(11, 1);
        self::issued(11, 2);
        $third = self::issued(11, 3);
        self::assertTrue(C::withdraw((string) $third['id'], 11));

        self::assertTrue(C::issue(11, 'operator-one', self::valoper(4))['ok']);
    }

    public function testAMemberIsIssuedAtMostFivePerHour(): void
    {
        $t0                      = 1_900_000_000;
        $GLOBALS['__bcc_clock'] = $t0;
        for ($i = 0; $i < C::MAX_ISSUED_PER_MEMBER_PER_HOUR; $i++) {
            self::issued(11, 7); // each replaces the last, and each counts
        }
        self::assertSame(C::REFUSED_MEMBER_RATE, C::issue(11, 'operator-one', self::valoper(7))['reason']);
        self::assertSame(C::REFUSED_MEMBER_RATE, C::issue(11, 'operator-one', self::valoper(8))['reason']);

        // The replaced challenges are long dead, yet still count for the hour.
        $GLOBALS['__bcc_clock'] = $t0 + 600;
        self::assertSame(C::REFUSED_MEMBER_RATE, C::issue(11, 'operator-one', self::valoper(8))['reason']);
        $GLOBALS['__bcc_clock'] = $t0 + 3601;
        self::assertTrue(C::issue(11, 'operator-one', self::valoper(8))['ok'], 'an hour on');
    }

    /** Live for 30 minutes (it stops counting); kept 30 more for a submitted hash. */
    public function testTheLifetimeAndTheVerificationGrace(): void
    {
        $t0                      = 1_900_000_000;
        $GLOBALS['__bcc_clock'] = $t0;
        $a = (string) self::issued(11, 1)['id'];
        self::issued(11, 2);
        self::issued(11, 3);
        self::assertSame(C::REFUSED_MEMBER_LIVE, C::issue(11, 'operator-one', self::valoper(4))['reason']);

        $GLOBALS['__bcc_clock'] = $t0 + C::LIFETIME + 1;
        self::assertNotNull(C::reserve($a, 11, 'operator-one', self::HASH), 'a hash may still be submitted after the window');
        self::assertTrue(C::issue(11, 'operator-one', self::valoper(4))['ok'], 'past their window they no longer count');

        $GLOBALS['__bcc_clock'] = $t0 + C::LIFETIME + C::VERIFY_GRACE + 1;
        self::assertNull(C::peek($a), 'gone after the grace');
        self::assertNull(C::reserve($a, 11, 'operator-one', self::HASH));
    }

    /** No refusal at normal levels; from 5 members on one valoper, a signal. */
    public function testFiveMembersOnOneValoperRaiseTheCrowdingSignal(): void
    {
        for ($u = 1; $u < C::CROWDED_VALOPER_MEMBERS; $u++) {
            self::issued($u, 7, 'member-' . $u);
        }
        self::assertSame(0, DegradationMetrics::readEvent('validator_memo_claim', 'crowded_valoper', time()));
        self::assertSame([], Logger::$log);

        self::issued(C::CROWDED_VALOPER_MEMBERS, 7, 'member-5');

        self::assertSame(1, DegradationMetrics::readEvent('validator_memo_claim', 'crowded_valoper', time()));
        self::assertCount(1, Logger::$log);
        self::assertSame(self::valoper(7), Logger::$log[0][2]['valoper'] ?? null);
        self::assertSame(5, Logger::$log[0][2]['members'] ?? null);
        self::assertSame(0, DegradationMetrics::readEvent('validator_memo_claim', 'crowded_valoper', time() - 7200), 'an hourly bucket');
    }

    public function testTheValoperBackstopRefusesNewcomersButAlwaysRenewsAMembersOwn(): void
    {
        for ($u = 1; $u <= C::MAX_LIVE_PER_VALOPER; $u++) {
            self::issued($u, 7, 'member-' . $u);
        }

        $newcomer = C::issue(1000, 'newcomer', self::valoper(7));
        self::assertSame(C::REFUSED_VALOPER_BACKSTOP, $newcomer['reason']);
        self::assertSame(1, DegradationMetrics::readEvent('validator_memo_claim', 'valoper_backstop', time()));

        self::assertTrue(C::issue(1, 'member-1', self::valoper(7))['ok'], 'a member\'s own is renewable at the backstop');
        self::assertTrue(C::issue(1000, 'newcomer', self::valoper(8))['ok'], 'other validators are unaffected');
    }

    // ── reserve, withdraw, consume ──────────────────────────────────────

    public function testReserveTakesOneHashPerChallenge(): void
    {
        $c  = self::issued(11, 7);
        $id = (string) $c['id'];

        $reserved = C::reserve($id, 11, 'operator-one', strtolower(self::HASH));
        self::assertSame(self::HASH, $reserved['reserved_hash'] ?? null, 'stored uppercase');
        self::assertGreaterThan(0, $reserved['reserved_at'] ?? 0);

        self::assertNotNull(C::reserve($id, 11, 'operator-one', self::HASH), 'the same hash again');
        self::assertNull(C::reserve($id, 11, 'operator-one', str_repeat('1', 64)), 'any other hash');
    }

    public function testReserveIsRefusedToAnyoneElseAndOnAChangedHandle(): void
    {
        $id = (string) self::issued(11, 7)['id'];

        self::assertNull(C::reserve($id, 22, 'operator-one', self::HASH), 'another member');
        self::assertNull(C::reserve($id, 11, 'renamed', self::HASH), 'the handle changed since the memo was issued');
        self::assertNull(C::reserve($id, 11, 'operator-one', 'not-a-hash'));
        self::assertNull(C::reserve('nope', 11, 'operator-one', self::HASH));
        self::assertSame('', C::peek($id)['reserved_hash'] ?? null, 'still unreserved');
    }

    public function testWithdrawIsTheMembersOwnAndEndsTheChallenge(): void
    {
        $id = (string) self::issued(11, 7)['id'];

        self::assertFalse(C::withdraw($id, 22));
        self::assertTrue(C::withdraw($id, 11));
        self::assertFalse(C::withdraw($id, 11), 'already withdrawn');
        self::assertNull(C::reserve($id, 11, 'operator-one', self::HASH));
    }

    public function testConsumeInTransactionTakesOnlyTheMembersReservedChallenge(): void
    {
        $id = (string) self::issued(11, 7)['id'];
        C::reserve($id, 11, 'operator-one', self::HASH);

        $this->db->query('START TRANSACTION');
        self::assertNull(C::consumeInTransaction($id, 11, 'operator-one', str_repeat('2', 64)), 'another hash');
        $this->db->query('ROLLBACK');

        $this->db->query('START TRANSACTION');
        self::assertNull(C::consumeInTransaction($id, 22, 'operator-one', self::HASH), 'another member');
        $this->db->query('ROLLBACK');

        $this->db->query('START TRANSACTION');
        self::assertNull(C::consumeInTransaction($id, 11, 'renamed', self::HASH), 'a changed handle');
        $this->db->query('ROLLBACK');

        $this->db->query('START TRANSACTION');
        $c = C::consumeInTransaction($id, 11, 'operator-one', strtolower(self::HASH));
        $this->db->query('COMMIT');
        self::assertSame($id, $c['id'] ?? null);
        self::assertNull(C::peek($id), 'used once');
    }

    public function testAnUnreservedOrWithdrawnChallengeIsNotConsumed(): void
    {
        $unreserved = (string) self::issued(11, 7)['id'];
        $withdrawn  = (string) self::issued(11, 8)['id'];
        C::reserve($withdrawn, 11, 'operator-one', self::HASH);
        C::withdraw($withdrawn, 11);

        $this->db->query('START TRANSACTION');
        self::assertNull(C::consumeInTransaction($unreserved, 11, 'operator-one', self::HASH));
        self::assertNull(C::consumeInTransaction($withdrawn, 11, 'operator-one', self::HASH));
        $this->db->query('ROLLBACK');
    }

    public function testAWithdrawalFreesASlotUnderTheValoperBackstop(): void
    {
        $first = null;
        for ($u = 1; $u <= C::MAX_LIVE_PER_VALOPER; $u++) {
            $c     = self::issued($u, 7, 'member-' . $u);
            $first = $first ?? $c;
        }
        self::assertSame(C::REFUSED_VALOPER_BACKSTOP, C::issue(1000, 'newcomer', self::valoper(7))['reason']);

        self::assertTrue(C::withdraw((string) $first['id'], 1));
        self::assertTrue(C::issue(1000, 'newcomer', self::valoper(7))['ok']);
    }

    /** A row under the challenge prefix that is not a memo challenge is never one. */
    public function testOnlyAMemoChallengeIsReadReservedOrConsumed(): void
    {
        $id = str_repeat('a', 24);
        $this->db->rows['_transient_bcc_vmc_' . $id] = serialize([
            'purpose' => 'verify', 'user_id' => 11, 'handle' => 'operator-one', 'withdrawn_at' => 0,
            'reserved_hash' => self::HASH, 'expires_at' => time() + 600,
        ]);

        self::assertNull(C::peek($id));
        self::assertNull(C::reserve($id, 11, 'operator-one', self::HASH));
        $this->db->query('START TRANSACTION');
        self::assertNull(C::consumeInTransaction($id, 11, 'operator-one', self::HASH));
        $this->db->query('ROLLBACK');
    }

    /** An id is a challenge id: one naming an index row is refused before anything reads or deletes it. */
    public function testAnIdThatIsNotAChallengeIdTouchesNothing(): void
    {
        self::issued(11, 7);
        $indexRow = '_transient_bcc_vmc_iu_11';
        self::assertArrayHasKey($indexRow, $this->db->rows);
        $before = $this->db->rows[$indexRow];

        self::assertNull(C::peek('iu_11'));
        self::assertNull(C::reserve('iu_11', 11, 'operator-one', self::HASH));
        self::assertFalse(C::withdraw('iu_11', 11));
        $this->db->query('START TRANSACTION');
        self::assertNull(C::consumeInTransaction('iu_11', 11, 'operator-one', self::HASH));
        self::assertSame($before, $this->db->rows[$indexRow] ?? null, 'the index row was not taken');
        $this->db->query('ROLLBACK');
    }

    public function testConsumeIsRefusedOutsideATransaction(): void
    {
        $id = (string) self::issued(11, 7)['id'];
        C::reserve($id, 11, 'operator-one', self::HASH);

        $this->expectException(\LogicException::class);
        C::consumeInTransaction($id, 11, 'operator-one', self::HASH);
    }
}
