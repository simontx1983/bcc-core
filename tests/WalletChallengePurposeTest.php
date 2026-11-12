<?php

declare(strict_types=1);

namespace BCC\Core\Wallet\Tests;

use BCC\Core\Crypto\WalletChallenge;
use BCC\Core\Wallet\WalletIdentityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-253: a signed-in member confirms an email change, a password change or
 * an account delete by signing a wallet challenge. The challenge carries its
 * purpose:
 *   - the message says what is being approved (and that a delete is final);
 *   - consumeChallenge() refuses a challenge issued for another purpose, in
 *     every direction, and the refusal still spends it (single-use);
 *   - a caller that names no purpose gets PURPOSE_VERIFY only, so wallet
 *     linking (and pre-F-253 bcc-trust) can never spend a confirmation;
 *   - a payload stored before purposes existed counts as PURPOSE_VERIFY;
 *   - the anonymous sign-in purpose cannot be issued on the signed-in path.
 *
 * Against the REAL WalletIdentityService + ChallengeRepository over the
 * in-memory wp_options double AnonymousWalletChallengeTest uses.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WalletChallengePurposeTest extends TestCase
{
    private const USER = 7;
    private const ADDR = '0xAbCdEf0000000000000000000000000000000002';

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

    private static function issue(string $purpose): array
    {
        return WalletIdentityService::generateChallenge(self::USER, 'ethereum', 1, self::ADDR, $purpose);
    }

    /** @return array<string, array{string, string}> */
    public static function confirmations(): array
    {
        return [
            'email'    => [WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE, 'Confirm changing the email address of your Blue Collar Crypto account (bluecollarcrypto.io). This request'],
            'password' => [WalletChallenge::PURPOSE_CONFIRM_PASSWORD_CHANGE, 'Confirm changing the password of your Blue Collar Crypto account (bluecollarcrypto.io). This request'],
            'delete'   => [WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE, 'Confirm deleting your Blue Collar Crypto account (bluecollarcrypto.io). This cannot be undone. This request'],
        ];
    }

    #[DataProvider('confirmations')]
    public function testTheMessageSaysWhatIsBeingApprovedAndTheMatchingConsumeTakesIt(string $purpose, string $lead): void
    {
        $c = self::issue($purpose);

        self::assertStringStartsWith($lead, $c['message']);
        self::assertStringEndsWith(' Chain: ethereum', $c['message']);
        self::assertSame($purpose, $c['purpose']);

        $consumed = WalletIdentityService::consumeChallenge(self::USER, strtoupper(self::ADDR), $purpose);
        self::assertNotNull($consumed);
        self::assertSame($c['message'], $consumed['message'], 'verifiers sign-check the STORED message');
        self::assertNull(WalletIdentityService::consumeChallenge(self::USER, self::ADDR, $purpose), 'single-use');
    }

    /** @return iterable<string, array{string, string}> */
    public static function mismatches(): iterable
    {
        foreach (WalletChallenge::AUTHED_PURPOSES as $issued) {
            foreach (WalletChallenge::AUTHED_PURPOSES as $spent) {
                if ($issued !== $spent) {
                    yield $issued . ' spent as ' . $spent => [$issued, $spent];
                }
            }
        }
    }

    #[DataProvider('mismatches')]
    public function testAChallengeIsNeverSpentOnAnotherPurposeAndTheTryUsesItUp(string $issued, string $spent): void
    {
        self::issue($issued);

        self::assertNull(WalletIdentityService::consumeChallenge(self::USER, self::ADDR, $spent));
        self::assertNull(
            WalletIdentityService::consumeChallenge(self::USER, self::ADDR, $issued),
            'a wrong-purpose try spends it, so it cannot be retried'
        );
    }

    public function testACallerNamingNoPurposeOnlyGetsAVerifyChallenge(): void
    {
        self::issue(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE);
        self::assertNull(WalletIdentityService::consumeChallenge(self::USER, self::ADDR), 'wallet linking cannot spend a delete confirmation');

        $verify = WalletIdentityService::generateChallenge(self::USER, 'ethereum', 1, self::ADDR);
        self::assertSame(WalletChallenge::PURPOSE_VERIFY, $verify['purpose'], 'the default issue stays verify');
        self::assertNotNull(WalletIdentityService::consumeChallenge(self::USER, self::ADDR));
    }

    public function testAPayloadStoredBeforePurposesExistedCountsAsVerify(): void
    {
        self::issue(WalletChallenge::PURPOSE_VERIFY);
        $db  = $GLOBALS['wpdb'];
        $key = '_transient_bcc_wc_' . self::USER . '_' . md5(strtolower(self::ADDR));
        self::assertArrayHasKey($key, $db->rows);
        $row = unserialize($db->rows[$key]);
        unset($row['purpose']);
        $db->rows[$key] = serialize($row);

        self::assertNull(
            WalletIdentityService::consumeChallenge(self::USER, self::ADDR, WalletChallenge::PURPOSE_CONFIRM_EMAIL_CHANGE),
            'an old challenge is never a confirmation'
        );

        self::issue(WalletChallenge::PURPOSE_VERIFY);
        $row = unserialize($db->rows[$key]);
        unset($row['purpose']);
        $db->rows[$key] = serialize($row);
        self::assertNotNull(WalletIdentityService::consumeChallenge(self::USER, self::ADDR), 'but still links a wallet');
    }

    public function testTheSignInPurposeAndUnknownPurposesCannotBeIssuedSignedIn(): void
    {
        foreach ([WalletChallenge::PURPOSE_SIGN_IN, 'delete', ''] as $bad) {
            try {
                self::issue($bad);
                self::fail('issued a signed-in challenge for ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], array_keys($GLOBALS['wpdb']->rows), 'nothing stored');
        self::assertTrue(WalletChallenge::isPurpose(WalletChallenge::PURPOSE_CONFIRM_ACCOUNT_DELETE));
        self::assertFalse(WalletChallenge::isPurpose('delete'));
    }
}
