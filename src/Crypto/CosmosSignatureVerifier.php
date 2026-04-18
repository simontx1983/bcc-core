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
     * @param string $chainId       Chain ID used to build the canonical sign doc.
     * @return bool
     */
    public static function verify(
        string $message,
        string $signature,
        string $address,
        string $pubKeyB64,
        string $chainId = 'cosmoshub-4'
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

        // 3b. Derive the Cosmos address from the public key and verify it
        //     matches the claimed address. Without this check, an attacker
        //     can sign with their own key but claim a victim's address.
        $derivedAddress = self::deriveCosmosAddress($pubKeyRaw, $address);
        if (!hash_equals($derivedAddress, $address)) {
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

    /**
     * Produce canonical (sorted-keys, no-spaces) JSON recursively.
     *
     * @param array<string, mixed> $data
     */
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

    /**
     * Derive a Cosmos bech32 address from a compressed secp256k1 public key.
     *
     * Steps: SHA-256 → RIPEMD-160 → bech32-encode with the chain's HRP.
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

        // Cosmos address = RIPEMD160(SHA256(compressed_pubkey))
        $sha256Hash   = hash('sha256', $pubKeyRaw, true);
        $ripemd160Hash = hash('ripemd160', $sha256Hash, true);

        // Convert the 20-byte hash from 8-bit groups to 5-bit groups for bech32.
        $converted = self::convertBits($ripemd160Hash, 8, 5, true);
        if ($converted === null) {
            return '';
        }

        return self::bech32Encode($hrp, $converted);
    }

    /**
     * Convert data between bit groups (e.g., 8-bit bytes to 5-bit bech32 groups).
     *
     * @param string $data    Raw binary data.
     * @param int    $fromBits Source bit width.
     * @param int    $toBits   Target bit width.
     * @param bool   $pad      Whether to pad the final group.
     * @return int[]|null Array of integers in the target bit width, or null on error.
     */
    private static function convertBits(string $data, int $fromBits, int $toBits, bool $pad): ?array
    {
        $acc    = 0;
        $bits   = 0;
        $result = [];
        $maxV   = (1 << $toBits) - 1;

        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $value = ord($data[$i]);
            if (($value >> $fromBits) !== 0) {
                return null;
            }
            $acc  = ($acc << $fromBits) | $value;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits    -= $toBits;
                $result[] = ($acc >> $bits) & $maxV;
            }
        }

        if ($pad) {
            if ($bits > 0) {
                $result[] = ($acc << ($toBits - $bits)) & $maxV;
            }
        } elseif ($bits >= $fromBits || (($acc << ($toBits - $bits)) & $maxV) !== 0) {
            return null;
        }

        return $result;
    }

    /**
     * Encode data as a bech32 string (BIP-173).
     *
     * @param string $hrp   Human-readable part (e.g., "cosmos").
     * @param int[]  $data  Array of 5-bit integers.
     * @return string The bech32-encoded string.
     */
    private static function bech32Encode(string $hrp, array $data): string
    {
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

        $values  = array_merge(self::bech32HrpExpand($hrp), $data);
        $checksum = self::bech32CreateChecksum($hrp, $data);

        $encoded = $hrp . '1';
        foreach (array_merge($data, $checksum) as $d) {
            $encoded .= $charset[$d];
        }

        return $encoded;
    }

    /**
     * Expand the HRP for checksum computation.
     *
     * @param string $hrp
     * @return int[]
     */
    private static function bech32HrpExpand(string $hrp): array
    {
        $expand = [];
        $len    = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $expand[] = ord($hrp[$i]) >> 5;
        }
        $expand[] = 0;
        for ($i = 0; $i < $len; $i++) {
            $expand[] = ord($hrp[$i]) & 31;
        }
        return $expand;
    }

    /**
     * Compute the bech32 checksum.
     *
     * @param string $hrp
     * @param int[]  $data
     * @return int[] 6-element checksum array.
     */
    private static function bech32CreateChecksum(string $hrp, array $data): array
    {
        $values = array_merge(self::bech32HrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0]);
        $polymod = self::bech32Polymod($values) ^ 1;
        $checksum = [];
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }
        return $checksum;
    }

    /**
     * Internal polymod function for bech32 checksum.
     *
     * @param int[] $values
     * @return int
     */
    private static function bech32Polymod(array $values): int
    {
        $generator = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= $generator[$i];
                }
            }
        }
        return $chk;
    }
}
