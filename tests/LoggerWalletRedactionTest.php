<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Log\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Wallet-address redaction in the shared Logger (docs/wallet-privacy-policy.md).
 *
 * This class was the source of two of the 2026-07-23 leaks:
 *   - the redaction keyed on the EXACT names `address` / `wallet_address`, so
 *     five call sites that logged under the key `wallet` wrote FULL addresses to
 *     disk, one of them (`BonusService`) next to `user_id`;
 *   - the "safe" partial redaction reduced to `first-6…last-4`, which IS the
 *     forbidden shortened form, again next to `user_id`.
 *
 * The fix: substring key matching + a salt-keyed HMAC fingerprint. These tests
 * pin both, and — critically — assert the output never contains the address in
 * whole OR in the first-6/last-4 window, so a regression to truncation fails.
 */
final class LoggerWalletRedactionTest extends TestCase
{
    // Synthetic, unmistakably-not-a-real-wallet fixture (review item #4).
    // Non-hex letters at both edges keep the truncation-regression
    // assertions collision-proof against a hex fingerprint.
    private const ADDRESS = '0xEXAMPLEonlyNOTArealWALLETfixtureonlyZZ01';

    protected function setUp(): void
    {
        // fingerprintAddress() keys on wp_salt(); provide a deterministic one.
        if (!function_exists('wp_salt')) {
            eval('function wp_salt($scheme = "auth") { return "unit-test-fixed-salt"; }');
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function redact(array $context): array
    {
        $ref = new ReflectionMethod(Logger::class, 'redactSensitive');
        $ref->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $ref->invoke(null, $context);
        return $out;
    }

    private static function assertNoAddressLeak(string $haystack): void
    {
        $lower = strtolower($haystack);
        self::assertStringNotContainsString(strtolower(self::ADDRESS), $lower, 'full address leaked');
        // Old-bug truncation form was first-6 + last-4; assert both edges.
        self::assertStringNotContainsString(strtolower(substr(self::ADDRESS, 0, 6)), $lower, 'address prefix leaked (truncation regression)');
        self::assertStringNotContainsString(strtolower(substr(self::ADDRESS, -4)), $lower, 'address suffix leaked (truncation regression)');
    }

    /**
     * The key the old exact-match list MISSED. Full address, verbatim, was
     * written to disk under `wallet` at five call sites.
     *
     * @return iterable<string, array{string}>
     */
    public static function walletKeyProvider(): iterable
    {
        yield "'wallet' (the missed key)" => ['wallet'];
        yield "'wallet_address'"          => ['wallet_address'];
        yield "'address'"                 => ['address'];
        yield "'operator_address'"        => ['operator_address'];
        yield "'valoper'"                 => ['valoper'];
        yield "'owner_wallet'"            => ['owner_wallet'];
    }

    #[DataProvider('walletKeyProvider')]
    public function testWalletBearingKeysAreFingerprintedNotTruncated(string $key): void
    {
        $out = self::redact([$key => self::ADDRESS, 'user_id' => 42]);

        self::assertIsString($out[$key]);
        self::assertStringStartsWith('wallet_fp:', $out[$key]);
        self::assertNoAddressLeak(json_encode($out, JSON_THROW_ON_ERROR));
        // The correlation value (user_id) is fine to keep — only the wallet goes.
        self::assertSame(42, $out['user_id']);
    }

    public function testFingerprintIsStableForTheSameAddress(): void
    {
        $a = self::redact(['wallet' => self::ADDRESS]);
        $b = self::redact(['wallet' => strtoupper(self::ADDRESS)]);

        // Same wallet (case-insensitive) → same fingerprint, so logs still
        // correlate two lines about one wallet without disclosing it.
        self::assertSame($a['wallet'], $b['wallet']);
    }

    public function testFingerprintDiffersForDifferentAddresses(): void
    {
        $a = self::redact(['wallet' => self::ADDRESS]);
        $b = self::redact(['wallet' => '0x0000000000000000000000000000000000000001']);

        self::assertNotSame($a['wallet'], $b['wallet']);
    }

    /**
     * ip_address is an abuse-investigation field, not a member wallet, and must
     * survive intact; contract/mint addresses are public on-chain identifiers.
     */
    public function testNonWalletAddressKeysAreNotFingerprinted(): void
    {
        $out = self::redact([
            'ip_address'       => '203.0.113.7',
            'contract_address' => '0xcontract0000000000000000000000000000beef',
            'mint'             => 'So11111111111111111111111111111111111111112',
        ]);

        self::assertSame('203.0.113.7', $out['ip_address']);
        self::assertSame('0xcontract0000000000000000000000000000beef', $out['contract_address']);
        self::assertSame('So11111111111111111111111111111111111111112', $out['mint']);
    }

    public function testNestedContextIsRedactedRecursively(): void
    {
        $out = self::redact(['signal' => ['wallet' => self::ADDRESS, 'chain' => 'ethereum']]);

        self::assertIsArray($out['signal']);
        self::assertStringStartsWith('wallet_fp:', $out['signal']['wallet']);
        self::assertSame('ethereum', $out['signal']['chain']);
        self::assertNoAddressLeak(json_encode($out, JSON_THROW_ON_ERROR));
    }

    public function testSecretsAreStillFullyRedacted(): void
    {
        // Pre-existing behaviour must be preserved by the refactor.
        $out = self::redact(['helius_api_key' => 'sk-live-abc', 'authorization' => 'Bearer xyz']);

        self::assertSame('***REDACTED***', $out['helius_api_key']);
        self::assertSame('***REDACTED***', $out['authorization']);
    }
}
