<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Crypto\Bech32;
use BCC\Core\Crypto\Cosmos\CosmosTxVerdict;
use BCC\Core\Crypto\Cosmos\CosmosTxVerifier;
use BCC\Core\Crypto\Cosmos\ProtoReader;
use BCC\Core\Tests\Support\CosmosTxKit as K;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/CosmosTxKit.php';

/**
 * Every shape rule of CosmosTxVerifier (design §4.3), each with a negative
 * vector that is VALIDLY SIGNED, so the rule under test is the only thing
 * that refuses it. Transactions come from CosmosTxKit, which builds and
 * signs them independently of the verifier, with a fresh secp256k1 key.
 */
#[CoversClass(CosmosTxVerifier::class)]
#[CoversClass(CosmosTxVerdict::class)]
#[CoversClass(ProtoReader::class)]
final class CosmosTxVerifierShapeTest extends TestCase
{
    private K $kit;

    protected function setUp(): void
    {
        if (!extension_loaded('gmp') || !function_exists('openssl_pkey_new')) {
            self::markTestSkipped('Requires ext-gmp and ext-openssl.');
        }
        $kit = K::create();
        if ($kit === null) {
            self::markTestSkipped('OpenSSL build lacks secp256k1 keygen (or no openssl.cnf found).');
        }
        $this->kit = $kit;
    }

    /** @param array{raw: string, hash: string} $tx */
    private function verify(array $tx, ?string $operator = null, string $chainId = K::CHAIN_ID, int $account = K::ACCOUNT): CosmosTxVerdict
    {
        return CosmosTxVerifier::verifySelfSend($tx['raw'], $tx['hash'], $chainId, $account, $operator ?? $this->kit->valoper());
    }

    // ── the controls ────────────────────────────────────────────────────

    public function testADirectSelfSendFromTheOperatorVerifies(): void
    {
        $v = $this->verify($this->kit->tx());

        self::assertTrue($v->ok, 'refused: ' . $v->reason);
        self::assertSame('BCC: claim validator page test. Code ABCD2345', $v->memo);
        self::assertSame($this->kit->address(), $v->fromAddress);
        self::assertSame($this->kit->address(), $v->toAddress);
        self::assertSame([['denom' => 'uatom', 'amount' => '1']], $v->amount);
        self::assertSame('direct', $v->signMode);
        self::assertSame(7, $v->sequence);
    }

    public function testAnAminoJsonSelfSendVerifies(): void
    {
        $v = $this->verify($this->kit->tx(['mode' => 127]));

        self::assertTrue($v->ok, 'refused: ' . $v->reason);
        self::assertSame('amino_json', $v->signMode);
        self::assertSame('BCC: claim validator page test. Code ABCD2345', $v->memo);
    }

    /** Account number 0 and an empty fee and memo: the zero values the encoders omit, or write as "0" / []. */
    public function testZeroAndEmptyValuesSignAsTheSdkWritesThem(): void
    {
        foreach ([1, 127] as $mode) {
            $tx = $this->kit->tx(['mode' => $mode, 'account_number' => 0, 'fee' => [], 'memo' => '', 'sequence' => 0]);
            $v  = $this->verify($tx, account: 0);
            self::assertTrue($v->ok, "mode {$mode} refused: {$v->reason}");
            self::assertSame('', $v->memo);
        }
    }

    public function testATimeoutHeightIsAcceptedUnderDirectOnly(): void
    {
        self::assertTrue($this->verify($this->kit->tx(['timeout_height' => 123456]))->ok);
        self::assertSame(
            CosmosTxVerdict::REASON_TIMEOUT_HEIGHT,
            $this->verify($this->kit->tx(['mode' => 127, 'timeout_height' => 123456]))->reason
        );
    }

    // ── every shape rule ────────────────────────────────────────────────

    /** @return array<string, array{0: \Closure(K): array<string, mixed>, 1: string}> */
    public static function shapeRefusals(): array
    {
        $send = static fn (K $k): string => K::msgSend($k->address(), $k->address());

        return [
            'an extension option' => [
                static fn (K $k): array => ['body_extra' => K::bytesField(1023, K::any('/injective.types.v1beta1.ExtensionOptionsWeb3Tx', ''))],
                CosmosTxVerdict::REASON_EXTENSION_OPTIONS,
            ],
            'a non-critical extension option' => [
                static fn (K $k): array => ['body_extra' => K::bytesField(2047, K::any('/cosmos.example.Option', ''))],
                CosmosTxVerdict::REASON_EXTENSION_OPTIONS,
            ],
            'no message' => [static fn (K $k): array => ['msgs' => []], CosmosTxVerdict::REASON_MESSAGE_COUNT],
            'two messages' => [static fn (K $k): array => ['msgs' => [$send($k), $send($k)]], CosmosTxVerdict::REASON_MESSAGE_COUNT],
            'a MsgSend wrapped in MsgExec' => [
                static fn (K $k): array => ['msgs' => [K::any(
                    '/cosmos.authz.v1beta1.MsgExec',
                    K::bytesField(1, $k->address()) . K::bytesField(2, $send($k))
                )]],
                CosmosTxVerdict::REASON_MESSAGE_TYPE,
            ],
            'another message type' => [
                static fn (K $k): array => ['msgs' => [K::any('/cosmos.staking.v1beta1.MsgDelegate', K::bytesField(1, $k->address()))]],
                CosmosTxVerdict::REASON_MESSAGE_TYPE,
            ],
            'two signer infos' => [static fn (K $k): array => ['signer_infos' => 2], CosmosTxVerdict::REASON_SIGNER_COUNT],
            'no signer info' => [static fn (K $k): array => ['signer_infos' => 0], CosmosTxVerdict::REASON_SIGNER_COUNT],
            'no signature' => [static fn (K $k): array => ['signatures' => []], CosmosTxVerdict::REASON_SIGNATURE_COUNT],
            'two signatures' => [
                static fn (K $k): array => ['signatures' => [str_repeat("\x01", 64), str_repeat("\x01", 64)]],
                CosmosTxVerdict::REASON_SIGNATURE_COUNT,
            ],
            'a multisig mode' => [static fn (K $k): array => ['mode_info' => K::bytesField(2, '')], CosmosTxVerdict::REASON_MULTISIG],
            'a multisig key' => [
                static fn (K $k): array => ['key_any' => K::any('/cosmos.crypto.multisig.LegacyAminoPubKey', K::varintField(1, 1))],
                CosmosTxVerdict::REASON_MULTISIG,
            ],
            'no mode' => [static fn (K $k): array => ['mode_info' => ''], CosmosTxVerdict::REASON_SIGN_MODE],
            'SIGN_MODE_UNSPECIFIED' => [static fn (K $k): array => ['mode_info' => K::modeInfo(0)], CosmosTxVerdict::REASON_SIGN_MODE],
            'SIGN_MODE_TEXTUAL' => [static fn (K $k): array => ['mode_info' => K::modeInfo(2)], CosmosTxVerdict::REASON_SIGN_MODE],
            'SIGN_MODE_DIRECT_AUX' => [static fn (K $k): array => ['mode_info' => K::modeInfo(3)], CosmosTxVerdict::REASON_SIGN_MODE],
            'SIGN_MODE_EIP_191' => [static fn (K $k): array => ['mode_info' => K::modeInfo(191)], CosmosTxVerdict::REASON_SIGN_MODE],
            'an ed25519 key' => [
                static fn (K $k): array => ['key_any' => K::any('/cosmos.crypto.ed25519.PubKey', K::bytesField(1, str_repeat("\x07", 32)))],
                CosmosTxVerdict::REASON_KEY_TYPE,
            ],
            'an ethsecp256k1 key on a non-Ethermint chain' => [
                static fn (K $k): array => ['key_any' => K::secpKeyAny($k->pubKey, CosmosTxVerifier::KEY_ETHSECP256K1)],
                CosmosTxVerdict::REASON_KEY_TYPE,
            ],
            'a secp256k1 key on an Ethermint chain' => [
                static fn (K $k): array => ['from' => $k->address('inj')],
                CosmosTxVerdict::REASON_KEY_TYPE,
            ],
            'no public key' => [static fn (K $k): array => ['key_any' => null], CosmosTxVerdict::REASON_PUBLIC_KEY],
            'a key that is not on the curve' => [
                static fn (K $k): array => ['key_any' => K::secpKeyAny("\x02" . str_repeat("\xff", 32))],
                CosmosTxVerdict::REASON_PUBLIC_KEY,
            ],
            'an uncompressed key' => [
                static fn (K $k): array => ['key_any' => K::secpKeyAny("\x04" . str_repeat("\x01", 64))],
                CosmosTxVerdict::REASON_PUBLIC_KEY,
            ],
            'a fee payer' => [static fn (K $k): array => ['payer' => $k->address()], CosmosTxVerdict::REASON_FEE_PAYER],
            'a fee granter' => [static fn (K $k): array => ['granter' => $k->address()], CosmosTxVerdict::REASON_FEE_PAYER],
            'a tip' => [static fn (K $k): array => ['auth_extra' => K::bytesField(3, '')], CosmosTxVerdict::REASON_UNSUPPORTED_FIELD],
            'an unordered transaction' => [static fn (K $k): array => ['body_extra' => K::varintField(4, 1)], CosmosTxVerdict::REASON_UNSUPPORTED_FIELD],
            'a timeout timestamp' => [static fn (K $k): array => ['body_extra' => K::bytesField(5, '')], CosmosTxVerdict::REASON_UNSUPPORTED_FIELD],
            'an unknown TxRaw field' => [static fn (K $k): array => ['raw_extra' => K::bytesField(4, 'x')], CosmosTxVerdict::REASON_UNSUPPORTED_FIELD],
            'the memo twice' => [static fn (K $k): array => ['body_extra' => K::bytesField(2, 'a second memo')], CosmosTxVerdict::REASON_MALFORMED],
            'the memo with the wrong wire type' => [static fn (K $k): array => ['memo' => '', 'body_extra' => K::varintField(2, 1)], CosmosTxVerdict::REASON_MALFORMED],
            'a truncated field' => [static fn (K $k): array => ['raw_extra' => "\x1a\x05ab"], CosmosTxVerdict::REASON_MALFORMED],
            'an overlong varint' => [static fn (K $k): array => ['body_extra' => "\x18" . str_repeat("\xff", 9) . "\x02"], CosmosTxVerdict::REASON_MALFORMED],
            'a sender that is not bech32' => [static fn (K $k): array => ['from' => 'cosmos1notbech32', 'to' => $k->address()], CosmosTxVerdict::REASON_MALFORMED],
            'non-ASCII text under amino' => [static fn (K $k): array => ['mode' => 127, 'memo' => "caf\xc3\xa9"], CosmosTxVerdict::REASON_AMINO_TEXT],
            'a control character under amino' => [static fn (K $k): array => ['mode' => 127, 'memo' => "line\nbreak"], CosmosTxVerdict::REASON_AMINO_TEXT],
            'a non-numeric coin amount under amino' => [static fn (K $k): array => ['mode' => 127, 'amount' => [['uatom', '1.5']]], CosmosTxVerdict::REASON_AMINO_TEXT],
            'a high-S signature' => [static fn (K $k): array => ['high_s' => true], CosmosTxVerdict::REASON_SIGNATURE_INVALID],
            'an amino doc signed with another sequence' => [
                static fn (K $k): array => ['mode' => 127, 'amino_doc' => K::aminoDoc(K::CHAIN_ID, K::ACCOUNT, 8, [['uatom', '5000']], 200000, 'BCC: claim validator page test. Code ABCD2345', $k->address(), $k->address(), [['uatom', '1']])],
                CosmosTxVerdict::REASON_SIGNATURE_INVALID,
            ],
            'not a self-send' => [
                static fn (K $k): array => ['to' => Bech32::encode('cosmos', str_repeat("\x22", 20))],
                CosmosTxVerdict::REASON_NOT_SELF_SEND,
            ],
        ];
    }

    /** @param \Closure(K): array<string, mixed> $options */
    #[DataProvider('shapeRefusals')]
    public function testEachShapeRuleRefusesAValidlySignedTransaction(\Closure $options, string $reason): void
    {
        $v = $this->verify($this->kit->tx($options($this->kit)));

        self::assertFalse($v->ok);
        self::assertSame($reason, $v->reason);
        self::assertSame('', $v->memo, 'a refusal carries nothing from the transaction');
        self::assertSame('', $v->fromAddress);
        self::assertSame([], $v->amount);
    }

    // ── who signed ──────────────────────────────────────────────────────

    public function testASignatureByAnotherKeyIsRefused(): void
    {
        $other = K::create();
        self::assertNotNull($other);

        self::assertSame(
            CosmosTxVerdict::REASON_SIGNATURE_INVALID,
            $this->verify($this->kit->tx(['sign_with' => $other]))->reason
        );
    }

    public function testAKeyThatIsNotTheSendersIsRefused(): void
    {
        // Validly signed by this key, but sent "from" another account.
        $other = Bech32::encode('cosmos', str_repeat("\x33", 20));

        self::assertSame(
            CosmosTxVerdict::REASON_SIGNER_NOT_SENDER,
            $this->verify($this->kit->tx(['from' => $other, 'to' => $other]))->reason
        );
    }

    public function testASelfSendByAnotherAccountIsNotTheOperators(): void
    {
        $operator = Bech32::encode('cosmosvaloper', str_repeat("\x44", 20));

        self::assertSame(CosmosTxVerdict::REASON_NOT_OPERATOR, $this->verify($this->kit->tx(), $operator)->reason);
    }

    // ── the amino JSON encoding ─────────────────────────────────────────

    /**
     * `<`, `>` and `&` sign as Go's encoding/json writes them (<,
     * >, &: HTML-safe), with `"` and `\` backslash-escaped and
     * `/` left alone. A signature over PHP's unescaped JSON does not verify.
     */
    public function testAminoTextIsEscapedAsGoWritesIt(): void
    {
        $memo = 'a<b>&c"d\\e/f';
        $from = $this->kit->address();
        $go   = '{"account_number":"4242","chain_id":"testchain-1","fee":{"amount":[{"amount":"5000","denom":"uatom"}],"gas":"200000"}'
            . ',"memo":"a\\u003cb\\u003e\\u0026c\\"d\\\\e/f"'
            . ',"msgs":[{"type":"cosmos-sdk/MsgSend","value":{"amount":[{"amount":"1","denom":"uatom"}],"from_address":"' . $from . '","to_address":"' . $from . '"}}]'
            . ',"sequence":"7"}';

        $v = $this->verify($this->kit->tx(['mode' => 127, 'memo' => $memo, 'amino_doc' => $go]));
        self::assertTrue($v->ok, 'refused: ' . $v->reason);
        self::assertSame($memo, $v->memo);

        self::assertSame(
            CosmosTxVerdict::REASON_SIGNATURE_INVALID,
            $this->verify($this->kit->tx(['mode' => 127, 'memo' => $memo]))->reason,
            'signed over json_encode output, which leaves < > & unescaped'
        );
    }

    /** Under DIRECT the memo is inside the signed body bytes, so any bytes are fine and come back as they are. */
    public function testADirectMemoComesBackByteForByte(): void
    {
        $memo = "caf\xc3\xa9 \x00 <&>";
        self::assertSame($memo, $this->verify($this->kit->tx(['memo' => $memo]))->memo);
    }

    // ── the caller's input ──────────────────────────────────────────────

    public function testUnusableCallerInputIsRefused(): void
    {
        $tx = $this->kit->tx();

        foreach (['', 'abc', str_repeat('g', 64), $tx['hash'] . '00'] as $hash) {
            self::assertSame(CosmosTxVerdict::REASON_BAD_INPUT, CosmosTxVerifier::verifySelfSend($tx['raw'], $hash, K::CHAIN_ID, K::ACCOUNT, $this->kit->valoper())->reason, "hash '{$hash}'");
        }
        self::assertSame(CosmosTxVerdict::REASON_BAD_INPUT, $this->verify($tx, account: -1)->reason);
        self::assertSame(CosmosTxVerdict::REASON_BAD_INPUT, $this->verify($tx, chainId: '')->reason);
        self::assertSame(CosmosTxVerdict::REASON_BAD_INPUT, $this->verify($tx, chainId: "chain\n1")->reason);
        self::assertSame(CosmosTxVerdict::REASON_BAD_INPUT, $this->verify($tx, chainId: str_repeat('c', 65))->reason);
        self::assertSame(CosmosTxVerdict::REASON_BAD_INPUT, $this->verify($tx, 'cosmosvaloper1notbech32')->reason);
        self::assertSame(
            CosmosTxVerdict::REASON_BAD_INPUT,
            $this->verify($tx, Bech32::encode('cosmosvaloper', str_repeat("\x01", 32)))->reason,
            'an operator account is 20 bytes'
        );
    }

    public function testTheBytesMustBeTheSubmittedTransaction(): void
    {
        $tx    = $this->kit->tx();
        $other = $this->kit->tx(['memo' => 'another']);

        self::assertSame(CosmosTxVerdict::REASON_HASH_MISMATCH, $this->verify(['raw' => $tx['raw'], 'hash' => $other['hash']])->reason);
        self::assertSame(CosmosTxVerdict::REASON_MALFORMED, $this->verify(['raw' => '', 'hash' => hash('sha256', '')])->reason);

        $big = $this->kit->tx(['memo' => str_repeat('m', CosmosTxVerifier::MAX_TX_BYTES)]);
        self::assertSame(CosmosTxVerdict::REASON_MALFORMED, $this->verify($big)->reason, 'over the size cap');
    }

    public function testTheChainIdAndAccountNumberAreThoseSigned(): void
    {
        foreach ([1, 127] as $mode) {
            $tx = $this->kit->tx(['mode' => $mode]);
            self::assertSame(CosmosTxVerdict::REASON_SIGNATURE_INVALID, $this->verify($tx, chainId: 'testchain-2')->reason);
            self::assertSame(CosmosTxVerdict::REASON_SIGNATURE_INVALID, $this->verify($tx, account: K::ACCOUNT + 1)->reason);
        }
    }
}
