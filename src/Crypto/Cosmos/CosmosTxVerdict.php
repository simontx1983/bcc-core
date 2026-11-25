<?php
/**
 * What CosmosTxVerifier concluded about one transaction.
 *
 * `ok` is the only thing that proves anything. On a refusal `reason` is one
 * of the REASON_* codes (for logs and tests; never shown to a user as-is),
 * and every other field is empty. On success the fields come from the
 * verified, signature-covered bytes:
 *   - memo: the TxBody memo, raw bytes, for a byte-for-byte comparison;
 *   - fromAddress / toAddress: exactly as written in the MsgSend;
 *   - amount: the MsgSend coins, in order;
 *   - signMode: 'direct' or 'amino_json';
 *   - sequence: the signer's sequence from the signed AuthInfo.
 *
 * @package BCC\Core\Crypto\Cosmos
 */

namespace BCC\Core\Crypto\Cosmos;

if (!defined('ABSPATH')) {
    exit;
}

final class CosmosTxVerdict
{
    /** The caller's own input is unusable (hash format, chain id, account number, operator address). */
    public const REASON_BAD_INPUT = 'bad_input';
    /** sha256(tx bytes) is not the submitted hash. */
    public const REASON_HASH_MISMATCH = 'hash_mismatch';
    /** Not decodable protobuf, too large, or an address that is not valid bech32. */
    public const REASON_MALFORMED = 'malformed';
    /** A field the verifier does not accept (tip, unordered, timeout timestamp, unknown fields). */
    public const REASON_UNSUPPORTED_FIELD = 'unsupported_field';
    /** extension_options or non_critical_extension_options is not empty. */
    public const REASON_EXTENSION_OPTIONS = 'extension_options';
    /** timeout_height is set on a LEGACY_AMINO_JSON transaction (allowed under DIRECT). */
    public const REASON_TIMEOUT_HEIGHT = 'timeout_height';
    /** Not exactly one message. */
    public const REASON_MESSAGE_COUNT = 'message_count';
    /** The message is not a /cosmos.bank.v1beta1.MsgSend (MsgExec and every other type included). */
    public const REASON_MESSAGE_TYPE = 'message_type';
    /** Not exactly one signer_info. */
    public const REASON_SIGNER_COUNT = 'signer_count';
    /** Not exactly one signature. */
    public const REASON_SIGNATURE_COUNT = 'signature_count';
    /** A multisig signer (mode_info.multi, or a multisig key). */
    public const REASON_MULTISIG = 'multisig';
    /** A sign mode other than DIRECT and LEGACY_AMINO_JSON (TEXTUAL, DIRECT_AUX, EIP-191, unspecified, ...). */
    public const REASON_SIGN_MODE = 'sign_mode';
    /** A key type other than secp256k1 / ethsecp256k1, or the wrong one of the two for the chain. */
    public const REASON_KEY_TYPE = 'key_type';
    /** No public key, or one that is not a valid compressed secp256k1 point. */
    public const REASON_PUBLIC_KEY = 'public_key';
    /** The fee names a payer or a granter. */
    public const REASON_FEE_PAYER = 'fee_payer';
    /** LEGACY_AMINO_JSON over text the verifier will not encode (anything outside printable ASCII). */
    public const REASON_AMINO_TEXT = 'amino_text';
    /** The signature does not verify over the sign document. */
    public const REASON_SIGNATURE_INVALID = 'signature_invalid';
    /** The key that signed is not the sender's. */
    public const REASON_SIGNER_NOT_SENDER = 'signer_not_sender';
    /** from_address and to_address differ. */
    public const REASON_NOT_SELF_SEND = 'not_self_send';
    /** The sender is not the validator's operator account. */
    public const REASON_NOT_OPERATOR = 'not_operator';

    /**
     * @param list<array{denom: string, amount: string}> $amount
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly string $memo,
        public readonly string $fromAddress,
        public readonly string $toAddress,
        public readonly array $amount,
        public readonly string $signMode,
        public readonly int $sequence
    ) {
    }

    public static function refused(string $reason): self
    {
        return new self(false, $reason, '', '', '', [], '', 0);
    }

    /**
     * @param list<array{denom: string, amount: string}> $amount
     */
    public static function verified(
        string $memo,
        string $fromAddress,
        string $toAddress,
        array $amount,
        string $signMode,
        int $sequence
    ): self {
        return new self(true, '', $memo, $fromAddress, $toAddress, $amount, $signMode, $sequence);
    }
}
