<?php

declare(strict_types=1);

namespace BCC\Core\Wallet\Tests;

use BCC\Core\Wallet\WalletIdentityService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-25 — a stranger can no longer replace or pin a wallet owner's pending
 * sign-in challenge.
 *
 * /auth/wallet-nonce is anonymous and takes any address. Pinned against the
 * REAL WalletIdentityService + ChallengeRepository over an in-memory
 * wp_options table:
 *   - a live (address, chain) challenge is never overwritten — a second
 *     request gets the same one back; an expired one is replaced;
 *   - challenges are per (address, chain): another chain neither replaces
 *     nor pins one (EVM addresses are shared across EVM chains);
 *   - peekAnonymousChallenges lists them, non-destructively;
 *   - consume with a chain id takes only that chain's challenge, once;
 *     consume without one (pre-F-25 bcc-trust) takes the first;
 *   - a challenge stored under the pre-F-25 address-only key still works;
 *   - generation is serialised by a per-key advisory lock, and still
 *     generates when the lock cannot be had;
 *   - the anonymous challenge carries the sign-in text, the authed one the
 *     verify text.
 *
 * Subprocess-only; setUp() loads the options-table $wpdb double before
 * anything autoloads AdvisoryLock.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnonymousWalletChallengeTest extends TestCase
{
    private const ADDR = '0xAbCdEf0000000000000000000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/challenge-options-wpdb.php';
        require_once __DIR__ . '/Stubs/site-url-stubs.php';
        \BccTestSiteUrls::reset();
        $GLOBALS['wpdb'] = new \BccTestOptionsWpdb();
        \BCC\Core\DB\AdvisoryLock::$log  = [];
        \BCC\Core\DB\AdvisoryLock::$deny = false;
    }

    private static function db(): \BccTestOptionsWpdb
    {
        /** @var \BccTestOptionsWpdb $db */
        $db = $GLOBALS['wpdb'];
        return $db;
    }

    private static function prefix(): string
    {
        return 'bcc_wc_anon_' . md5(strtolower(self::ADDR));
    }

    public function testASecondRequestGetsTheSameLiveChallengeBack(): void
    {
        $first  = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);
        $second = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, strtoupper(self::ADDR));

        self::assertSame($first, $second, 'never overwritten while live — case-insensitive address');
        self::assertStringStartsWith('Sign in to Blue Collar Crypto (bluecollarcrypto.io). ', $first['message']);
        self::assertStringEndsWith(' Chain: ethereum', $first['message']);
    }

    public function testAnExpiredChallengeIsReplaced(): void
    {
        $first = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);
        $key   = '_transient_' . self::prefix() . '_1';
        $row   = unserialize(self::db()->rows[$key]);
        $row['expires_at'] = time() - 1;
        self::db()->rows[$key] = serialize($row);

        self::assertSame([], WalletIdentityService::peekAnonymousChallenges(self::ADDR), 'an expired challenge is not offered');
        self::assertNull(WalletIdentityService::consumeAnonymousChallenge(self::ADDR, 1));

        $second = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);

        self::assertNotSame($first['nonce'], $second['nonce']);
        self::assertGreaterThan(time(), $second['expires_at']);
    }

    public function testAnotherChainNeitherReplacesNorPinsTheChallenge(): void
    {
        $eth  = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);
        $base = WalletIdentityService::generateAnonymousChallenge('base', 8, self::ADDR);

        self::assertSame('base', $base['chain_slug'], 'the asked-for chain, not the live one for ethereum');
        self::assertNotSame($eth['nonce'], $base['nonce']);

        $live = WalletIdentityService::peekAnonymousChallenges(self::ADDR);
        self::assertSame([$eth['nonce'], $base['nonce']], array_column($live, 'nonce'), 'both stay live');
    }

    public function testConsumeWithAChainTakesOnlyThatChainsChallengeOnce(): void
    {
        $eth  = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);
        $base = WalletIdentityService::generateAnonymousChallenge('base', 8, self::ADDR);

        $got = WalletIdentityService::consumeAnonymousChallenge(self::ADDR, 8);

        self::assertSame($base['nonce'], $got['nonce'] ?? null);
        self::assertNull(WalletIdentityService::consumeAnonymousChallenge(self::ADDR, 8), 'one-time');
        self::assertSame([$eth['nonce']], array_column(WalletIdentityService::peekAnonymousChallenges(self::ADDR), 'nonce'), 'the other chain is untouched');
        self::assertNull(WalletIdentityService::consumeAnonymousChallenge(self::ADDR, 999), 'no challenge for that chain');
    }

    public function testPeekingDoesNotConsume(): void
    {
        $eth = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);

        WalletIdentityService::peekAnonymousChallenges(self::ADDR);
        WalletIdentityService::peekAnonymousChallenge(self::ADDR);

        self::assertSame($eth['nonce'], WalletIdentityService::consumeAnonymousChallenge(self::ADDR, 1)['nonce'] ?? null);
    }

    public function testPreF25CallersStillWork(): void
    {
        $eth = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);

        self::assertSame($eth['nonce'], WalletIdentityService::peekAnonymousChallenge(self::ADDR)['nonce'] ?? null);
        self::assertSame($eth['nonce'], WalletIdentityService::consumeAnonymousChallenge(self::ADDR)['nonce'] ?? null);
        self::assertNull(WalletIdentityService::consumeAnonymousChallenge(self::ADDR));
    }

    public function testAChallengeUnderThePreF25KeyStillWorks(): void
    {
        $legacy = [
            'nonce' => 'legacynonce', 'message' => 'old text', 'chain_slug' => 'ethereum', 'chain_id' => 1,
            'wallet_address' => strtolower(self::ADDR), 'expires_at' => time() + 120,
        ];
        self::db()->rows['_transient_' . self::prefix()]         = serialize($legacy);
        self::db()->rows['_transient_timeout_' . self::prefix()] = (string) (time() + 120);

        self::assertSame(['legacynonce'], array_column(WalletIdentityService::peekAnonymousChallenges(self::ADDR), 'nonce'));
        self::assertSame('legacynonce', WalletIdentityService::consumeAnonymousChallenge(self::ADDR, 1)['nonce'] ?? null);
        self::assertSame([], self::db()->rows, 'value and timeout rows both deleted');
    }

    public function testAnotherAddressIsInvisible(): void
    {
        WalletIdentityService::generateAnonymousChallenge('ethereum', 1, '0x0000000000000000000000000000000000000002');

        self::assertSame([], WalletIdentityService::peekAnonymousChallenges(self::ADDR));
        self::assertNull(WalletIdentityService::consumeAnonymousChallenge(self::ADDR));
    }

    public function testGenerationIsSerialisedPerKey(): void
    {
        WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);

        $key = self::prefix() . '_1';
        self::assertSame(['acquire:' . $key, 'release:' . $key], \BCC\Core\DB\AdvisoryLock::$log);
    }

    public function testAnUnavailableLockStillGeneratesAndReleasesNothing(): void
    {
        \BCC\Core\DB\AdvisoryLock::$deny = true;

        $c = WalletIdentityService::generateAnonymousChallenge('ethereum', 1, self::ADDR);

        self::assertNotSame('', $c['nonce']);
        self::assertSame(['acquire:' . self::prefix() . '_1'], \BCC\Core\DB\AdvisoryLock::$log);
    }

    public function testTheAuthedChallengeCarriesTheVerifyText(): void
    {
        $c = WalletIdentityService::generateChallenge(7, 'cosmoshub', 3, 'cosmos1abc');

        self::assertStringStartsWith('Verify your wallet on Blue Collar Crypto (bluecollarcrypto.io). ', $c['message']);
        self::assertStringEndsWith(' Chain: cosmoshub', $c['message']);
    }
}
