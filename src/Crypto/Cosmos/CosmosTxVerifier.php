<?php
/**
 * Cosmos transaction verifier: does this transaction prove that the
 * validator's operator key sent a self-send?
 *
 * The validator memo claim (design §4.3) rests on it. An endpoint only says
 * a transaction was included; THIS is what proves who signed it, verified
 * here over bytes whose hash is the one the member submitted. Nothing is
 * taken from an endpoint's JSON.
 *
 * verifySelfSend() accepts a transaction only when ALL of these hold, and
 * reports the first that fails (CosmosTxVerdict::REASON_*):
 *
 *  1. Hash. sha256(TxRaw bytes) equals the submitted hash.
 *  2. Bytes. TxRaw, TxBody, AuthInfo, SignerInfo, Fee, the message and the
 *     key decode strictly (ProtoReader): no field the verifier does not
 *     know (tip, unordered, timeout timestamp, ...), no field twice.
 *  3. Shape.
 *     - Exactly one message, a /cosmos.bank.v1beta1.MsgSend (so never one
 *       wrapped in MsgExec, which is a different message type).
 *     - Empty extension_options and non_critical_extension_options. A
 *       timeout_height only under DIRECT (inside the signed body bytes;
 *       Injective's SDK sets one by default), never under amino JSON.
 *     - Exactly one signer_info and one signature. No multisig.
 *     - Sign mode SIGN_MODE_DIRECT or SIGN_MODE_LEGACY_AMINO_JSON only
 *       (TEXTUAL, DIRECT_AUX, EIP-191 and the rest are refused).
 *     - The key is secp256k1, or ethsecp256k1 on an Ethermint chain
 *       (CosmosAddress::ETHERMINT_HRPS, judged from the sender's address);
 *       each chain only its own kind. A valid compressed point.
 *     - No fee payer or granter.
 *  4. Signature, verified HERE:
 *     - DIRECT: over the protobuf SignDoc {body_bytes, auth_info_bytes,
 *       chain_id, account_number}, built from the exact bytes in the tx;
 *     - LEGACY_AMINO_JSON (the Ledger path): over the canonical StdSignDoc
 *       JSON {account_number, chain_id, fee, memo, msgs, sequence}, built
 *       from the decoded fields, keys sorted, the Go encoder's escaping.
 *     secp256k1 signs SHA-256 of the sign bytes; ethsecp256k1 Keccak-256.
 *     Low-S only. Chain id and account number are the CALLER's; the
 *     sequence is the signed one (signer_info.sequence). A wrong account
 *     number only makes the signature fail.
 *  5. Addresses (20-byte comparisons, bech32 prefix ignored, as
 *     bcc-trust's ClaimService matches a validator):
 *     the key's address == from_address == to_address == the operator
 *     account (the valoper's bytes).
 *
 * ⚠ LOAD-BEARING. Three invariants carry the proof; none is a tidy-up:
 *  (a) Both extension option lists are EMPTY (rule 3). With an extension
 *      the chain can authenticate a transaction by other means, and then
 *      need not check signer_info's key against the sender: Osmosis smart
 *      accounts (an /osmosis.smartaccount.v1beta1.TxExtension in
 *      non_critical_extension_options selects a registered authenticator),
 *      Injective's EIP-712 path (ExtensionOptionsWeb3Tx in
 *      extension_options).
 *  (b) The signature is verified HERE, against signer_info's key (rule 4),
 *      and that key must derive the operator account (rule 5).
 *  (c) Nothing in the decision relies on the chain having accepted the
 *      transaction. Inclusion (bcc-trust's CosmosTxInclusionFetcher) only
 *      says it is on chain; who signed it is decided from these bytes
 *      alone.
 *
 * Pure: no WordPress, no network, no database. Never throws: anything it
 * cannot read is a refusal.
 *
 * @package BCC\Core\Crypto\Cosmos
 */

namespace BCC\Core\Crypto\Cosmos;

use BCC\Core\Crypto\Bech32;
use BCC\Core\Crypto\CosmosAddress;
use BCC\Core\Crypto\Keccak256;
use BCC\Core\Crypto\Secp256k1;

if (!defined('ABSPATH')) {
    exit;
}

final class CosmosTxVerifier
{
    public const MSG_SEND = '/cosmos.bank.v1beta1.MsgSend';

    public const KEY_SECP256K1    = '/cosmos.crypto.secp256k1.PubKey';
    public const KEY_ETHSECP256K1 = '/injective.crypto.v1beta1.ethsecp256k1.PubKey';

    /** cosmos.tx.signing.v1beta1.SignMode */
    public const SIGN_MODE_DIRECT            = 1;
    public const SIGN_MODE_LEGACY_AMINO_JSON = 127;

    /** A self-send is a few hundred bytes; anything this large is not one. */
    public const MAX_TX_BYTES = 16384;

    /** The amino name of MsgSend in a StdSignDoc. */
    private const AMINO_MSG_SEND = 'cosmos-sdk/MsgSend';

    private const B = ProtoReader::BYTES;
    private const V = ProtoReader::VARINT;

    /** @var array<int, array{0: int, 1: bool}> cosmos.tx.v1beta1.TxRaw */
    private const TX_RAW = [1 => [self::B, false], 2 => [self::B, false], 3 => [self::B, true]];

    /**
     * cosmos.tx.v1beta1.TxBody: messages, memo, timeout_height,
     * extension_options (1023), non_critical_extension_options (2047).
     * Not unordered (4) or timeout_timestamp (5).
     *
     * @var array<int, array{0: int, 1: bool}>
     */
    private const TX_BODY = [1 => [self::B, true], 2 => [self::B, false], 3 => [self::V, false], 1023 => [self::B, true], 2047 => [self::B, true]];

    /** @var array<int, array{0: int, 1: bool}> cosmos.tx.v1beta1.AuthInfo: signer_infos, fee. Not tip (3). */
    private const AUTH_INFO = [1 => [self::B, true], 2 => [self::B, false]];

    /** @var array<int, array{0: int, 1: bool}> cosmos.tx.v1beta1.SignerInfo: public_key, mode_info, sequence */
    private const SIGNER_INFO = [1 => [self::B, false], 2 => [self::B, false], 3 => [self::V, false]];

    /** @var array<int, array{0: int, 1: bool}> cosmos.tx.v1beta1.ModeInfo: single, multi */
    private const MODE_INFO = [1 => [self::B, false], 2 => [self::B, false]];

    /** @var array<int, array{0: int, 1: bool}> cosmos.tx.v1beta1.ModeInfo.Single: mode */
    private const MODE_SINGLE = [1 => [self::V, false]];

    /** @var array<int, array{0: int, 1: bool}> cosmos.tx.v1beta1.Fee: amount, gas_limit, payer, granter */
    private const FEE = [1 => [self::B, true], 2 => [self::V, false], 3 => [self::B, false], 4 => [self::B, false]];

    /** @var array<int, array{0: int, 1: bool}> google.protobuf.Any: type_url, value */
    private const ANY = [1 => [self::B, false], 2 => [self::B, false]];

    /** @var array<int, array{0: int, 1: bool}> cosmos.bank.v1beta1.MsgSend: from_address, to_address, amount */
    private const MSG_SEND_FIELDS = [1 => [self::B, false], 2 => [self::B, false], 3 => [self::B, true]];

    /** @var array<int, array{0: int, 1: bool}> cosmos.base.v1beta1.Coin: denom, amount */
    private const COIN = [1 => [self::B, false], 2 => [self::B, false]];

    /** @var array<int, array{0: int, 1: bool}> secp256k1 / ethsecp256k1 PubKey: key */
    private const PUB_KEY = [1 => [self::B, false]];

    /**
     * Does $txRaw prove a self-send from the validator's operator account?
     *
     * @param string $txRaw           The raw TxRaw bytes (binary).
     * @param string $expectedHashHex The submitted transaction hash, 64 hex digits, any case.
     * @param string $chainId         The chain id pinned in the caller's config, never from an endpoint.
     * @param int    $accountNumber   The operator account's account number.
     * @param string $operatorAddress The validator's operator address (bech32 valoper).
     */
    public static function verifySelfSend(
        string $txRaw,
        string $expectedHashHex,
        string $chainId,
        int $accountNumber,
        string $operatorAddress
    ): CosmosTxVerdict {
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $expectedHashHex) !== 1
            || $accountNumber < 0
            || $chainId === ''
            || strlen($chainId) > 64
            || !self::isPrintableAscii($chainId)
        ) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_BAD_INPUT);
        }
        $operator = Bech32::decode($operatorAddress);
        if ($operator === null || strlen($operator['bytes']) !== 20) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_BAD_INPUT);
        }

        // 1. The bytes are the submitted transaction.
        if ($txRaw === '' || strlen($txRaw) > self::MAX_TX_BYTES) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_MALFORMED);
        }
        if (!hash_equals(strtolower($expectedHashHex), hash('sha256', $txRaw))) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_HASH_MISMATCH);
        }

        try {
            return self::verifyDecoded($txRaw, $chainId, $accountNumber, $operator['bytes']);
        } catch (ProtoDecodeException $e) {
            return CosmosTxVerdict::refused($e->reason);
        }
    }

    /** @throws ProtoDecodeException */
    private static function verifyDecoded(string $txRaw, string $chainId, int $accountNumber, string $operatorBytes): CosmosTxVerdict
    {
        // 2. Decode, strictly.
        $tx            = ProtoReader::decode($txRaw, self::TX_RAW);
        $bodyBytes     = ProtoReader::bytes($tx, 1);
        $authInfoBytes = ProtoReader::bytes($tx, 2);
        $signatures    = ProtoReader::all($tx, 3);

        $body = ProtoReader::decode($bodyBytes, self::TX_BODY);
        $auth = ProtoReader::decode($authInfoBytes, self::AUTH_INFO);

        // 3. Shape: the body.
        // ⚠ LOAD-BEARING (invariant (a)): BOTH lists empty, the
        // non-critical one included. An extension can move authentication
        // off signer_info's key (Osmosis smart accounts in 2047, Injective
        // EIP-712 in 1023), and then a chain-accepted transaction proves
        // nothing about that key. Never relax this to an allowlist.
        if (ProtoReader::all($body, 1023) !== [] || ProtoReader::all($body, 2047) !== []) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_EXTENSION_OPTIONS);
        }
        $messages = ProtoReader::all($body, 1);
        if (count($messages) !== 1) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_MESSAGE_COUNT);
        }
        $any = ProtoReader::decode($messages[0], self::ANY);
        if (ProtoReader::bytes($any, 1) !== self::MSG_SEND) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_MESSAGE_TYPE);
        }
        $send   = ProtoReader::decode(ProtoReader::bytes($any, 2), self::MSG_SEND_FIELDS);
        $from   = ProtoReader::bytes($send, 1);
        $to     = ProtoReader::bytes($send, 2);
        $amount = self::coins(ProtoReader::all($send, 3));
        $memo   = ProtoReader::bytes($body, 2);

        // 3. Shape: the signer.
        $signerInfos = ProtoReader::all($auth, 1);
        if (count($signerInfos) !== 1) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_SIGNER_COUNT);
        }
        if (count($signatures) !== 1) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_SIGNATURE_COUNT);
        }
        $signer   = ProtoReader::decode($signerInfos[0], self::SIGNER_INFO);
        $modeInfo = ProtoReader::decode(ProtoReader::bytes($signer, 2), self::MODE_INFO);
        if (isset($modeInfo[2])) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_MULTISIG);
        }
        if (!isset($modeInfo[1])) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_SIGN_MODE);
        }
        $mode = ProtoReader::int(ProtoReader::decode(ProtoReader::bytes($modeInfo, 1), self::MODE_SINGLE), 1);
        if ($mode !== self::SIGN_MODE_DIRECT && $mode !== self::SIGN_MODE_LEGACY_AMINO_JSON) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_SIGN_MODE);
        }

        if (!isset($signer[1])) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_PUBLIC_KEY);
        }
        $keyAny  = ProtoReader::decode(ProtoReader::bytes($signer, 1), self::ANY);
        $keyType = ProtoReader::bytes($keyAny, 1);
        if ($keyType !== self::KEY_SECP256K1 && $keyType !== self::KEY_ETHSECP256K1) {
            return CosmosTxVerdict::refused(
                str_contains($keyType, 'multisig') ? CosmosTxVerdict::REASON_MULTISIG : CosmosTxVerdict::REASON_KEY_TYPE
            );
        }
        $fromDecoded = Bech32::decode($from);
        $toDecoded   = Bech32::decode($to);
        if ($fromDecoded === null || $toDecoded === null) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_MALFORMED);
        }
        // Each chain only its own kind of key: ethsecp256k1 exactly on the
        // Ethermint chains, judged from the sender's account prefix.
        $ethermint = CosmosAddress::isEthermintHrp($fromDecoded['hrp']);
        if ($ethermint !== ($keyType === self::KEY_ETHSECP256K1)) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_KEY_TYPE);
        }
        $pubKey = ProtoReader::bytes(ProtoReader::decode(ProtoReader::bytes($keyAny, 2), self::PUB_KEY), 1);
        if (Secp256k1::decompress($pubKey) === null) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_PUBLIC_KEY);
        }

        $fee = ProtoReader::decode(ProtoReader::bytes($auth, 2), self::FEE);
        if (ProtoReader::bytes($fee, 3) !== '' || ProtoReader::bytes($fee, 4) !== '') {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_FEE_PAYER);
        }
        $sequence = ProtoReader::int($signer, 3);

        // 4. The signature, over what this sign mode signs.
        if ($mode === self::SIGN_MODE_DIRECT) {
            $signBytes = self::directSignDoc($bodyBytes, $authInfoBytes, $chainId, $accountNumber);
            $signMode  = 'direct';
        } else {
            // A timeout height adds a key to the StdSignDoc whose encoding
            // has varied across SDK versions; refused rather than guessed.
            // (Under DIRECT it is inside the signed body bytes, so it is fine.)
            if (ProtoReader::int($body, 3) !== 0) {
                return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_TIMEOUT_HEIGHT);
            }
            $signBytes = self::aminoSignDoc(
                $chainId,
                $accountNumber,
                $sequence,
                self::coins(ProtoReader::all($fee, 1)),
                ProtoReader::int($fee, 2),
                $memo,
                $from,
                $to,
                $amount
            );
            if ($signBytes === null) {
                return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_AMINO_TEXT);
            }
            $signMode = 'amino_json';
        }
        // ⚠ LOAD-BEARING (invariant (b)): verified here, against
        // signer_info's key, never inferred from the chain having included
        // the transaction. Step 5 then binds that key to the operator.
        if (!self::signatureVerifies($signBytes, $signatures[0], $pubKey, $ethermint)) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_SIGNATURE_INVALID);
        }

        // 5. Addresses: the key's == the sender == the recipient == the operator.
        $signerBytes = CosmosAddress::bytesFromPubKey($pubKey, $ethermint);
        if ($signerBytes === null || !hash_equals($fromDecoded['bytes'], $signerBytes)) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_SIGNER_NOT_SENDER);
        }
        if (!hash_equals($fromDecoded['bytes'], $toDecoded['bytes'])) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_NOT_SELF_SEND);
        }
        if (!hash_equals($operatorBytes, $fromDecoded['bytes'])) {
            return CosmosTxVerdict::refused(CosmosTxVerdict::REASON_NOT_OPERATOR);
        }

        return CosmosTxVerdict::verified($memo, $from, $to, $amount, $signMode, $sequence);
    }

    /**
     * SIGN_MODE_DIRECT sign bytes: cosmos.tx.v1beta1.SignDoc, serialized as
     * the SDK does (fields in order, empty and zero values omitted), around
     * the EXACT body and auth-info bytes of the transaction.
     */
    private static function directSignDoc(string $bodyBytes, string $authInfoBytes, string $chainId, int $accountNumber): string
    {
        $doc = '';
        if ($bodyBytes !== '') {
            $doc .= "\x0a" . self::encodeVarint(strlen($bodyBytes)) . $bodyBytes;
        }
        if ($authInfoBytes !== '') {
            $doc .= "\x12" . self::encodeVarint(strlen($authInfoBytes)) . $authInfoBytes;
        }
        $doc .= "\x1a" . self::encodeVarint(strlen($chainId)) . $chainId;
        if ($accountNumber !== 0) {
            $doc .= "\x20" . self::encodeVarint($accountNumber);
        }
        return $doc;
    }

    /**
     * SIGN_MODE_LEGACY_AMINO_JSON sign bytes: the canonical StdSignDoc for
     * one MsgSend, as the Cosmos SDK builds it (keys sorted, no whitespace,
     * numbers as strings, empty coin lists as [], and Go's JSON escaping of
     * `<`, `>` and `&`). Fields that would add more keys are refused before
     * this point (fee payer/granter, timeout height, tip).
     *
     * Only printable ASCII is encoded; null for anything else, so this never
     * guesses at an escaping the chain might have done differently.
     *
     * @param list<array{denom: string, amount: string}> $fee
     * @param list<array{denom: string, amount: string}> $amount
     */
    private static function aminoSignDoc(
        string $chainId,
        int $accountNumber,
        int $sequence,
        array $fee,
        int $gas,
        string $memo,
        string $from,
        string $to,
        array $amount
    ): ?string {
        $chainJson  = self::jsonString($chainId);
        $memoJson   = self::jsonString($memo);
        $fromJson   = self::jsonString($from);
        $toJson     = self::jsonString($to);
        $feeJson    = self::coinsJson($fee);
        $amountJson = self::coinsJson($amount);
        if ($chainJson === null || $memoJson === null || $fromJson === null || $toJson === null
            || $feeJson === null || $amountJson === null
        ) {
            return null;
        }

        return '{"account_number":"' . $accountNumber . '"'
            . ',"chain_id":' . $chainJson
            . ',"fee":{"amount":' . $feeJson . ',"gas":"' . $gas . '"}'
            . ',"memo":' . $memoJson
            . ',"msgs":[{"type":"' . self::AMINO_MSG_SEND . '","value":{"amount":' . $amountJson
            . ',"from_address":' . $fromJson . ',"to_address":' . $toJson . '}}]'
            . ',"sequence":"' . $sequence . '"}';
    }

    /**
     * secp256k1: ECDSA over SHA-256 of the sign bytes (64-byte r||s).
     * ethsecp256k1: ECDSA over Keccak-256 of the sign bytes; the signature
     * may carry a 65th recovery byte, which, as Ethermint does, is ignored.
     * Low-S in both cases.
     */
    private static function signatureVerifies(string $signBytes, string $signature, string $pubKey, bool $ethermint): bool
    {
        if (!$ethermint) {
            return Secp256k1::verifySha256($signBytes, $signature, $pubKey); // 64 bytes or false
        }
        if (strlen($signature) === 65) {
            $signature = substr($signature, 0, 64);
        }
        return Secp256k1::verifyDigest(Keccak256::hash($signBytes, true), $signature, $pubKey); // 64 bytes or false
    }

    /**
     * @param list<string> $encoded Coin messages
     * @return list<array{denom: string, amount: string}>
     * @throws ProtoDecodeException
     */
    private static function coins(array $encoded): array
    {
        $out = [];
        foreach ($encoded as $bytes) {
            $coin  = ProtoReader::decode($bytes, self::COIN);
            $out[] = ['denom' => ProtoReader::bytes($coin, 1), 'amount' => ProtoReader::bytes($coin, 2)];
        }
        return $out;
    }

    /**
     * Amino JSON for a coin list: [{"amount":"…","denom":"…"},…] in order,
     * `[]` when empty. Null when an amount is not a plain non-negative
     * integer or a denom will not encode.
     *
     * @param list<array{denom: string, amount: string}> $coins
     */
    private static function coinsJson(array $coins): ?string
    {
        $parts = [];
        foreach ($coins as $coin) {
            $denom = self::jsonString($coin['denom']);
            if ($denom === null || preg_match('/\A[0-9]+\z/', $coin['amount']) !== 1) {
                return null;
            }
            $parts[] = '{"amount":"' . $coin['amount'] . '","denom":' . $denom . '}';
        }
        return '[' . implode(',', $parts) . ']';
    }

    /**
     * A JSON string exactly as Go's encoding/json writes it, for printable
     * ASCII only: `"` and `\` backslash-escaped, `<` `>` `&` as <
     * > & (Go's HTML-safe default, which the SDK's sorted sign
     * JSON keeps). Null for any other byte.
     */
    private static function jsonString(string $s): ?string
    {
        if (!self::isPrintableAscii($s)) {
            return null;
        }
        return '"' . strtr($s, [
            '\\' => '\\\\',
            '"'  => '\\"',
            '<'  => '\\u003c',
            '>'  => '\\u003e',
            '&'  => '\\u0026',
        ]) . '"';
    }

    private static function isPrintableAscii(string $s): bool
    {
        return $s === '' || preg_match('/\A[\x20-\x7e]+\z/', $s) === 1;
    }

    private static function encodeVarint(int $value): string
    {
        $out = '';
        while ($value > 0x7f) {
            $out .= chr(($value & 0x7f) | 0x80);
            $value >>= 7;
        }
        return $out . chr($value);
    }
}
