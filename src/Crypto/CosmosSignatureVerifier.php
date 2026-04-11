<?php
/**
 * Cosmos Signature Verifier
 *
 * Verifies a Cosmos wallet signature produced by Keplr's signAmino().
 * Keplr signs the canonical Amino JSON of a StdSignDoc using secp256k1 ECDSA.
 * The message digest is SHA-256 of the JSON bytes.
 * Uses OpenSSL for secp256k1 ECDSA verification.
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
     * Verify a Keplr signAmino signature.
     *
     * @param string $message       Plain-text nonce that was embedded in the StdSignDoc memo
     * @param string $signature     Base64-encoded 64-byte compact (r||s) secp256k1 signature
     * @param string $address       Bech32 Cosmos address (e.g. cosmos1abc…)
     * @param string $pubKeyB64     Base64-encoded 33-byte compressed secp256k1 public key
     * @param string $chainId       Chain ID (fallback if signedDocJson not provided)
     * @param string $signedDocJson The exact JSON Keplr signed (signed.signed from JS).
     *                              Preferred over rebuilding the doc from scratch.
     * @return bool
     */
    public static function verify(
        string $message,
        string $signature,
        string $address,
        string $pubKeyB64,
        string $chainId = 'cosmoshub-4',
        string $signedDocJson = ''
    ): bool {

        // 1. Build the canonical JSON from SERVER-KNOWN fields only.
        //    SECURITY: We NEVER use the client-submitted signed_doc as the
        //    canonical message. A client who controls their private key can
        //    forge any signed_doc with the right memo, and the server would
        //    verify against the attacker's document — not the server's.
        //    The server always builds the doc from: nonce (from transient),
        //    address (from POST param), chainId (from DB chain row).
        $signDoc = self::buildSignDoc($message, $address, $chainId);

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

        // 4. Convert compact r||s → DER-encoded ASN.1 for OpenSSL
        $der = self::rsToAsn1($sigRaw);
        if ($der === null) {
            return false;
        }

        // 5. Import compressed secp256k1 public key as OpenSSL key
        $ecKey = self::importCompressedPubKey($pubKeyRaw);
        if ($ecKey === false || $ecKey === null) {
            return false;
        }

        // 6. openssl_verify hashes $signDoc with SHA-256 internally, then checks the ECDSA sig
        $result = openssl_verify($signDoc, $der, $ecKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * Build the canonical Amino StdSignDoc JSON that Keplr signs.
     *
     * Keplr's signAmino produces a fixed structure. We embed the nonce
     * in the memo field of a zero-fee, empty-msgs document.
     * The JSON must be alphabetically sorted and have no extra whitespace.
     */
    private static function buildSignDoc(string $nonce, string $address, string $chainId): string {
        $doc = [
            'account_number' => '0',
            'chain_id'       => $chainId,
            'fee'            => [
                'amount' => [],
                'gas'    => '0',
            ],
            'memo'           => $nonce,
            'msgs'           => [],
            'sequence'       => '0',
        ];
        return self::canonicalJson($doc);
    }

    /** Produce canonical (sorted-keys, no-spaces) JSON recursively. */
    private static function canonicalJson(array $data): string {
        ksort($data);
        $parts = [];
        foreach ($data as $key => $value) {
            $encodedKey = json_encode((string) $key);
            if (is_array($value)) {
                if (empty($value)) {
                    $encodedValue = '[]';
                } elseif (array_keys($value) === range(0, count($value) - 1)) { // Indexed array
                    $items = [];
                    foreach ($value as $item) {
                        $items[] = is_array($item) ? self::canonicalJson($item) : json_encode($item);
                    }
                    $encodedValue = '[' . implode(',', $items) . ']';
                } else {
                    $encodedValue = self::canonicalJson($value);
                }
            } else {
                $encodedValue = json_encode($value);
            }
            $parts[] = $encodedKey . ':' . $encodedValue;
        }
        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Convert raw 64-byte (r||s) signature to DER-encoded ASN.1 for OpenSSL.
     *
     * @param  string      $rs 64 raw bytes: 32 bytes r + 32 bytes s
     * @return string|null DER binary or null on failure
     */
    private static function rsToAsn1(string $rs): ?string {
        if (strlen($rs) !== 64) {
            return null;
        }
        $r = substr($rs, 0, 32);
        $s = substr($rs, 32, 32);

        // Strip leading zeros, but keep at least one byte
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if (strlen($r) === 0) $r = "\x00";
        if (strlen($s) === 0) $s = "\x00";

        // If MSB is set, prepend 0x00 to indicate positive integer in DER
        if (ord($r[0]) >= 0x80) $r = "\x00" . $r;
        if (ord($s[0]) >= 0x80) $s = "\x00" . $s;

        $rLen = strlen($r);
        $sLen = strlen($s);

        $rDer = "\x02" . chr($rLen) . $r;
        $sDer = "\x02" . chr($sLen) . $s;

        $seqLen = strlen($rDer) + strlen($sDer);

        return "\x30" . chr($seqLen) . $rDer . $sDer;
    }

    /**
     * Import a compressed secp256k1 public key (33 bytes) as an OpenSSL key resource.
     *
     * Wraps the raw EC point in a SubjectPublicKeyInfo DER structure
     * and converts to PEM for OpenSSL import.
     *
     * @param  string $compressed 33 raw bytes
     * @return \OpenSSLAsymmetricKey|false|null
     */
    private static function importCompressedPubKey(string $compressed) {
        if (strlen($compressed) !== 33) {
            return null;
        }

        $oidEcPublicKey = "\x2a\x86\x48\xce\x3d\x02\x01";
        $oidSecp256k1   = "\x2b\x81\x04\x00\x0a";

        $algSeq = "\x30" . chr(2 + strlen($oidEcPublicKey) + 2 + strlen($oidSecp256k1))
                . "\x06" . chr(strlen($oidEcPublicKey)) . $oidEcPublicKey
                . "\x06" . chr(strlen($oidSecp256k1))   . $oidSecp256k1;

        $bitString = "\x03" . chr(1 + strlen($compressed)) . "\x00" . $compressed;

        $spki = "\x30" . chr(strlen($algSeq) + strlen($bitString)) . $algSeq . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($spki), 64, "\n")
             . "-----END PUBLIC KEY-----\n";

        return openssl_get_publickey($pem);
    }
}
