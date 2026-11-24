<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Crypto\Bech32;
use BCC\Core\Crypto\Cosmos\CosmosTxVerdict;
use BCC\Core\Crypto\Cosmos\CosmosTxVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CosmosTxVerifier against REAL mainnet transactions
 * (tests/fixtures/cosmos-real-txs.json: raw TxRaw bytes from each chain's
 * CometBFT RPC, the signer's account number from its REST API).
 *
 * Between them they cover both sign modes and both key kinds:
 *   - SIGN_MODE_DIRECT, secp256k1      (Cosmos Hub self-send)
 *   - LEGACY_AMINO_JSON, secp256k1     (Cosmos Hub self-send: the mode a
 *     Ledger signs in; also Hub, Osmosis and Akash sends with and without
 *     a memo)
 *   - SIGN_MODE_DIRECT, ethsecp256k1   (Injective self-send, with a timeout
 *     height, as Injective's SDK sets by default)
 *   - LEGACY_AMINO_JSON, ethsecp256k1  (Injective send: Keccak-256 over the
 *     amino JSON)
 *
 * The sends to another account are not self-sends, so they must stop at
 * REASON_NOT_SELF_SEND. The verifier checks the signature BEFORE the
 * address rules, so reaching that reason means the signature verified; the
 * wrong-account-number cases show the same vectors failing at the
 * signature.
 *
 * Each vector's operator address is its sender's bytes re-encoded as a
 * valoper: any 20-byte account is some validator's operator account.
 */
#[CoversClass(CosmosTxVerifier::class)]
#[CoversClass(CosmosTxVerdict::class)]
final class CosmosTxVerifierRealTxTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('Requires ext-gmp.');
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    private static function vectors(bool $selfSend): array
    {
        $json = file_get_contents(__DIR__ . '/fixtures/cosmos-real-txs.json');
        self::assertIsString($json);
        $all = json_decode($json, true);
        self::assertIsArray($all);

        $out = [];
        foreach ($all as $v) {
            if (($v['from'] === $v['to']) === $selfSend) {
                $out[(string) $v['name']] = [$v];
            }
        }
        return $out;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function selfSends(): array
    {
        return self::vectors(true);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function otherSends(): array
    {
        return self::vectors(false);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function allVectors(): array
    {
        return self::selfSends() + self::otherSends();
    }

    /** @param array<string, mixed> $v */
    private static function raw(array $v): string
    {
        $raw = base64_decode((string) $v['tx_base64'], true);
        self::assertIsString($raw);
        return $raw;
    }

    /** The sender's bytes as a valoper (or under $hrp). @param array<string, mixed> $v */
    private static function operatorOf(array $v, ?string $hrp = null): string
    {
        $from = Bech32::decode((string) $v['from']);
        self::assertNotNull($from);
        return Bech32::encode($hrp ?? $from['hrp'] . 'valoper', $from['bytes']);
    }

    /** @param array<string, mixed> $v */
    private static function verify(array $v, ?string $raw = null, ?string $hash = null, ?string $chainId = null, ?int $account = null, ?string $operator = null): CosmosTxVerdict
    {
        return CosmosTxVerifier::verifySelfSend(
            $raw ?? self::raw($v),
            $hash ?? (string) $v['hash'],
            $chainId ?? (string) $v['chain_id'],
            $account ?? (int) $v['account_number'],
            $operator ?? self::operatorOf($v)
        );
    }

    /** @param array<string, mixed> $v */
    #[DataProvider('selfSends')]
    public function testARealSelfSendFromTheOperatorVerifies(array $v): void
    {
        $verdict = self::verify($v);

        self::assertTrue($verdict->ok, 'refused: ' . $verdict->reason);
        self::assertSame('', $verdict->reason);
        self::assertSame((string) $v['memo'], $verdict->memo, 'the memo from the signed body');
        self::assertSame((string) $v['from'], $verdict->fromAddress);
        self::assertSame((string) $v['to'], $verdict->toAddress);
        self::assertSame($v['sign_mode'] === 1 ? 'direct' : 'amino_json', $verdict->signMode);
        self::assertSame((int) $v['sequence'], $verdict->sequence, 'the signed sequence');
        self::assertNotSame([], $verdict->amount);
        self::assertMatchesRegularExpression('/\A[0-9]+\z/', $verdict->amount[0]['amount']);
    }

    /** @param array<string, mixed> $v */
    #[DataProvider('otherSends')]
    public function testARealSendToAnotherAccountVerifiesItsSignatureThenIsRefusedAsNotASelfSend(array $v): void
    {
        self::assertSame(CosmosTxVerdict::REASON_NOT_SELF_SEND, self::verify($v)->reason);
    }

    /** @param array<string, mixed> $v */
    #[DataProvider('allVectors')]
    public function testTheWrongAccountNumberOrChainIdFailsTheSignature(array $v): void
    {
        self::assertSame(CosmosTxVerdict::REASON_SIGNATURE_INVALID, self::verify($v, account: (int) $v['account_number'] + 1)->reason);
        self::assertSame(CosmosTxVerdict::REASON_SIGNATURE_INVALID, self::verify($v, chainId: $v['chain_id'] . 'x')->reason);
    }

    /** @param array<string, mixed> $v */
    #[DataProvider('allVectors')]
    public function testATamperedSignatureFailsAndAnotherTransactionsHashIsRefused(array $v): void
    {
        $raw = self::raw($v);
        // The signature is TxRaw's last field; flip a bit inside it.
        $tampered = substr($raw, 0, -10) . chr(ord($raw[strlen($raw) - 10]) ^ 0x01) . substr($raw, -9);
        self::assertSame(
            CosmosTxVerdict::REASON_SIGNATURE_INVALID,
            self::verify($v, $tampered, hash('sha256', $tampered))->reason
        );

        self::assertSame(CosmosTxVerdict::REASON_HASH_MISMATCH, self::verify($v, $tampered)->reason, 'the submitted hash names other bytes');
    }

    /** @param array<string, mixed> $v */
    #[DataProvider('selfSends')]
    public function testARealSelfSendIsNotProofForAnotherOperator(array $v): void
    {
        $other = Bech32::encode('cosmosvaloper', str_repeat("\x11", 20));
        self::assertSame(CosmosTxVerdict::REASON_NOT_OPERATOR, self::verify($v, operator: $other)->reason);
    }

    /**
     * The operator's prefix is not compared, only its 20 bytes (as
     * ClaimService matches a validator), so a chain whose valoper prefix
     * is not "<account prefix>valoper" (Crypto.org's crocncl) still works.
     *
     * @param array<string, mixed> $v
     */
    #[DataProvider('selfSends')]
    public function testOnlyTheOperatorsBytesAreCompared(array $v): void
    {
        self::assertTrue(self::verify($v, operator: self::operatorOf($v, 'crocncl'))->ok);
    }

    /**
     * Injective's Go keyring (the injectived CLI a validator uses) signs
     * with go-ethereum's crypto.Sign, which returns 65 bytes [R‖S‖V], and
     * its PubKey.VerifySignature drops V. So an ethsecp256k1 signature may
     * carry that 65th byte; a secp256k1 one may not, and 66 bytes never.
     * Built from the real vectors by re-encoding TxRaw's signature field.
     */
    public function testOnlyAnEthermintSignatureMayCarryARecoveryByte(): void
    {
        $vectors   = self::selfSends();
        $injective = $vectors['injective_direct_selfsend'][0];
        $hub       = $vectors['cosmoshub_direct_selfsend'][0];

        foreach (["\x00", "\x01", "\x1c"] as $v) {
            $raw = self::withSignatureSuffix($injective, $v);
            self::assertTrue(self::verify($injective, $raw, hash('sha256', $raw))->ok, 'V = ' . bin2hex($v));
        }

        $raw = self::withSignatureSuffix($injective, "\x00\x00");
        self::assertSame(CosmosTxVerdict::REASON_SIGNATURE_INVALID, self::verify($injective, $raw, hash('sha256', $raw))->reason, '66 bytes');

        $raw = self::withSignatureSuffix($hub, "\x00");
        self::assertSame(CosmosTxVerdict::REASON_SIGNATURE_INVALID, self::verify($hub, $raw, hash('sha256', $raw))->reason, 'secp256k1, 65 bytes');
    }

    /** The vector's TxRaw with $suffix appended to its 64-byte signature. @param array<string, mixed> $v */
    private static function withSignatureSuffix(array $v, string $suffix): string
    {
        $raw = self::raw($v);
        self::assertSame("\x1a\x40", substr($raw, -66, 2), 'TxRaw ends with one 64-byte signature field');
        return substr($raw, 0, -66) . "\x1a" . chr(64 + strlen($suffix)) . substr($raw, -64) . $suffix;
    }

    /** @param array<string, mixed> $v */
    #[DataProvider('selfSends')]
    public function testTheSubmittedHashMayBeInEitherCase(array $v): void
    {
        self::assertTrue(self::verify($v, hash: strtolower((string) $v['hash']))->ok);
        self::assertTrue(self::verify($v, hash: strtoupper((string) $v['hash']))->ok);
    }
}
