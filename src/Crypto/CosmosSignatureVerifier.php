<?php
/**
 * Cosmos Signature Verifier
 *
 * Verifies a Cosmos wallet signature produced by Keplr's signAmino().
 * Keplr signs the canonical Amino JSON of a StdSignDoc using secp256k1 ECDSA.
 * The message digest is SHA-256 of the JSON bytes.
 * Uses OpenSSL for secp256k1 ECDSA verification (Secp256k1::verifySha256());
 * the address rule is CosmosAddress's and the encoding Bech32's.
 *
 * Requires: OpenSSL extension (standard on PHP).
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class CosmosSignatureVerifier {

    /**
     * Verify a Keplr `signArbitrary` (ADR-036) signature.
     *
     * The frontend calls Keplr's `signArbitrary(chainId, signer, data)`,
     * which signs a fixed ADR-036 envelope — NOT a regular StdSignDoc.
     * This verifier must reconstruct the same ADR-036 envelope or
     * openssl_verify will fail every time. (Earlier versions of this
     * verifier built a regular signAmino StdSignDoc, which produced
     * different bytes from what Keplr was signing — that mismatch was
     * the cause of every legacy "wallet signature didn't verify"
     * report on the REST path.)
     *
     * @param string $message       Plain-text nonce wrapped as ADR-036 `data` (base64-encoded inside the doc).
     * @param string $signature     Base64-encoded 64-byte compact (r||s) secp256k1 signature.
     * @param string $address       Bech32 Cosmos address (e.g. cosmos1abc…). Becomes the `signer` field.
     * @param string $pubKeyB64     Base64-encoded 33-byte compressed secp256k1 public key.
     * @param string $chainId       Ignored under ADR-036 (the spec mandates chain_id=""). Kept in the
     *                              signature for backwards-compatibility with the WalletVerifier facade.
     * @return bool
     */
    public static function verify(
        string $message,
        string $signature,
        string $address,
        string $pubKeyB64,
        string $chainId = 'cosmoshub-4'
    ): bool {
        unset($chainId); // ADR-036 fixes chain_id="" — not driven by chain config.

        // 1. Build the canonical ADR-036 envelope from SERVER-KNOWN fields only.
        //    SECURITY: We NEVER use the client-submitted signed_doc as the
        //    canonical message. A client who controls their private key can
        //    forge any signed_doc with the right `data` field, and the server
        //    would verify against the attacker's document — not the server's.
        //    The server always builds the doc from: nonce (from transient,
        //    base64-encoded into the `data` field) and address (from POST param).
        $signDoc = self::buildAdr036SignDoc($message, $address);

        // 2. Decode the signature (base64 → 64 raw bytes r||s)
        $sigRaw = base64_decode($signature, true);
        if (!is_string($sigRaw) || strlen($sigRaw) !== 64) {
            return false;
        }

        // 3. Decode the public key (must be 33-byte compressed point)
        $pubKeyRaw = base64_decode($pubKeyB64, true);
        if (!is_string($pubKeyRaw) || strlen($pubKeyRaw) !== 33) {
            return false;
        }

        // 3b. Derive the Cosmos address from the public key and verify it
        //     matches the claimed address. Without this check, an attacker
        //     can sign with their own key but claim a victim's address.
        $derivedAddress = self::deriveCosmosAddress($pubKeyRaw, $address);
        if (!hash_equals($derivedAddress, $address)) {
            return false;
        }

        // 4. ECDSA over SHA-256 of the sign-doc (OpenSSL hashes it), with the
        //    low-S malleability check: for every valid (r, s) there is a twin
        //    (r, n - s) that verifies for the same message, so accepting
        //    high-S would let an attacker mint a second distinct-on-disk
        //    signature. Cosmos SDK/Tendermint require canonical low-S and
        //    Keplr emits normalized signatures, so no legitimate client is
        //    rejected. Degenerate r/s == 0 is also rejected.
        return Secp256k1::verifySha256($signDoc, $sigRaw, $pubKeyRaw);
    }

    /**
     * Build the canonical ADR-036 (signArbitrary) sign-doc JSON.
     *
     * Per the ADR-036 spec — github.com/cosmos/cosmos-sdk/blob/main/docs/architecture/adr-036-arbitrary-signature.md —
     * an arbitrary-data signature is computed over a fixed envelope:
     *
     *   {
     *     "chain_id":       "",                  // mandatory empty
     *     "account_number": "0",                 // mandatory zero
     *     "sequence":       "0",                 // mandatory zero
     *     "fee":            {"gas":"0","amount":[]},
     *     "msgs": [{
     *       "type":  "sign/MsgSignData",
     *       "value": {
     *         "signer": "<bech32 address>",
     *         "data":   "<base64-encoded data>"
     *       }
     *     }],
     *     "memo": ""                             // mandatory empty
     *   }
     *
     * The `data` field is base64-encoded — Keplr's signArbitrary
     * encodes the input string this way before stuffing it into the
     * envelope. The canonical JSON is alphabetically sorted with no
     * whitespace, then SHA-256'd, then secp256k1 ECDSA-signed.
     *
     * Reconstructing the SAME bytes here is what makes openssl_verify
     * succeed; any divergence (extra/missing field, different order,
     * different `data` encoding) produces a different SHA-256 and the
     * signature is rejected.
     */
    private static function buildAdr036SignDoc(string $message, string $address): string
    {
        $doc = [
            'account_number' => '0',
            'chain_id'       => '',
            'fee'            => [
                'amount' => [],
                'gas'    => '0',
            ],
            'memo'           => '',
            'msgs'           => [
                [
                    'type'  => 'sign/MsgSignData',
                    'value' => [
                        'data'   => base64_encode($message),
                        'signer' => $address,
                    ],
                ],
            ],
            'sequence'       => '0',
        ];
        return self::canonicalJson($doc);
    }

    /**
     * Produce canonical (sorted-keys, no-spaces) JSON recursively.
     *
     * MUST byte-match what Keplr's JavaScript JSON.stringify produces
     * over the same envelope, because both sides feed the bytes into
     * SHA-256 before ECDSA. PHP's `json_encode()` defaults differ from
     * JS in two load-bearing ways:
     *
     *   - PHP escapes `/` → `\/` (JS doesn't). The ADR-036 envelope
     *     contains `"sign/MsgSignData"` and a base64-encoded `data`
     *     field whose alphabet includes `/`. Without
     *     JSON_UNESCAPED_SLASHES every signature on this path failed
     *     verification (legacy bug fixed 2026-05-07).
     *   - PHP escapes non-ASCII to `\uXXXX` (JS emits raw UTF-8).
     *     ADR-036 doesn't carry user text today, but JSON_UNESCAPED_UNICODE
     *     keeps the helper safe for any future field that does.
     *
     * @param array<string, mixed> $data
     */
    private static function canonicalJson(array $data): string {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        ksort($data);
        $parts = [];
        foreach ($data as $key => $value) {
            $encodedKey = json_encode((string) $key, $flags);
            if (is_array($value)) {
                if (empty($value)) {
                    $encodedValue = '[]';
                } elseif (array_keys($value) === range(0, count($value) - 1)) { // Indexed array
                    $items = [];
                    foreach ($value as $item) {
                        $items[] = is_array($item) ? self::canonicalJson($item) : json_encode($item, $flags);
                    }
                    $encodedValue = '[' . implode(',', $items) . ']';
                } else {
                    $encodedValue = self::canonicalJson($value);
                }
            } else {
                $encodedValue = json_encode($value, $flags);
            }
            $parts[] = $encodedKey . ':' . $encodedValue;
        }
        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Derive a Cosmos bech32 address from a compressed secp256k1 public key.
     *
     * Standard Cosmos chains: SHA-256 → RIPEMD-160 → bech32(HRP).
     * Ethermint chains (Injective): Keccak-256(uncompressed pubkey)[-20:]
     * → bech32(HRP), matching Ethereum's address derivation.
     *
     * @param string $pubKeyRaw  33-byte compressed public key.
     * @param string $address    The claimed bech32 address (used to extract the HRP).
     * @return string The derived bech32 address.
     */
    private static function deriveCosmosAddress(string $pubKeyRaw, string $address): string
    {
        // Extract the human-readable part (HRP) from the claimed address.
        // Bech32 format: <hrp>1<data>. The last '1' is the separator.
        $separatorPos = strrpos($address, '1');
        if ($separatorPos === false || $separatorPos === 0) {
            return ''; // Invalid bech32 — will fail hash_equals comparison.
        }
        $hrp = substr($address, 0, $separatorPos);

        $hash20 = CosmosAddress::bytesFromPubKey($pubKeyRaw, CosmosAddress::isEthermintHrp($hrp));
        if ($hash20 === null) {
            return '';
        }

        return Bech32::encode($hrp, $hash20);
    }
}
