<?php

declare(strict_types=1);

namespace BCC\Core\Crypto\Tests;

use BCC\Core\Crypto\WalletChallenge;
use BCC\Core\Support\FrontendOriginConfig;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-25 — the text a wallet signs names the site and says it costs nothing.
 *
 * User-approved wording (2026-11-11). The host comes from config — the first
 * EXACT entry of BCC_FRONTEND_ORIGIN, else home_url() — so staging names its
 * own host. The chain stays in the message (cross-chain replay guard).
 *
 * Subprocess-only: BCC_FRONTEND_ORIGIN is a constant, defined per test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletChallengeMessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/Stubs/site-url-stubs.php';
        \BccTestSiteUrls::reset();
    }

    public function testSignInTextIsTheApprovedWording(): void
    {
        define('BCC_FRONTEND_ORIGIN', 'https://bluecollarcrypto.io');

        $c = WalletChallenge::generate('ethereum', WalletChallenge::PURPOSE_SIGN_IN);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $c['nonce']);
        self::assertSame(
            'Sign in to Blue Collar Crypto (bluecollarcrypto.io). This request will not trigger a transaction or cost any fees. Code: '
                . $c['nonce'] . ' Chain: ethereum',
            $c['message']
        );
    }

    public function testVerifyTextIsUsedForLinkingAndClaims(): void
    {
        define('BCC_FRONTEND_ORIGIN', 'https://bluecollarcrypto.io');

        $c = WalletChallenge::generate('cosmoshub');

        self::assertSame(
            'Verify your wallet on Blue Collar Crypto (bluecollarcrypto.io). This request will not trigger a transaction or cost any fees. Code: '
                . $c['nonce'] . ' Chain: cosmoshub',
            $c['message'],
            'PURPOSE_VERIFY is the default'
        );
    }

    public function testStagingNamesItsOwnHostAndRegexEntriesAreSkipped(): void
    {
        define('BCC_FRONTEND_ORIGIN', ' regex:^https://bcc-.*\.vercel\.app$ , https://Stage.BlueCollarCrypto.io/ ,https://bluecollarcrypto.io');

        self::assertSame('https://Stage.BlueCollarCrypto.io', FrontendOriginConfig::canonical());
        self::assertStringStartsWith(
            'Sign in to Blue Collar Crypto (stage.bluecollarcrypto.io). ',
            WalletChallenge::generate('solana', WalletChallenge::PURPOSE_SIGN_IN)['message']
        );
    }

    public function testAPortIsKept(): void
    {
        define('BCC_FRONTEND_ORIGIN', 'http://localhost:3000');

        self::assertSame('localhost:3000', FrontendOriginConfig::publicHost());
    }

    public function testWithoutTheConstantTheHostFallsBackToHomeUrl(): void
    {
        \BccTestSiteUrls::$home = 'https://bluecollarcrypto.io';
        \BccTestSiteUrls::$site = 'https://cms.bluecollarcrypto.io';

        self::assertNull(FrontendOriginConfig::canonical());
        self::assertStringStartsWith(
            'Sign in to Blue Collar Crypto (bluecollarcrypto.io). ',
            WalletChallenge::generate('ethereum', WalletChallenge::PURPOSE_SIGN_IN)['message'],
            'home_url is the public front door; site_url (cms.) is never named'
        );
    }

    public function testOnlyRegexEntriesMeansNoCanonicalOrigin(): void
    {
        define('BCC_FRONTEND_ORIGIN', 'regex:^https://a\.example$');

        self::assertNull(FrontendOriginConfig::canonical());
    }

    public function testNoChainMeansNoChainSuffix(): void
    {
        define('BCC_FRONTEND_ORIGIN', 'https://bluecollarcrypto.io');

        self::assertStringEndsWith(
            'Code: ' . ($c = WalletChallenge::generate('', WalletChallenge::PURPOSE_SIGN_IN))['nonce'],
            $c['message']
        );
    }

    public function testAnUnknownPurposeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WalletChallenge::generate('ethereum', 'login');
    }

    public function testEveryNonceIsFresh(): void
    {
        define('BCC_FRONTEND_ORIGIN', 'https://bluecollarcrypto.io');

        self::assertNotSame(
            WalletChallenge::generate('ethereum')['nonce'],
            WalletChallenge::generate('ethereum')['nonce']
        );
    }
}
