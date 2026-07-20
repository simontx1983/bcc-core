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

    /** secp256k1 group order n (for the low-S malleability check). */
    private const SECP256K1_N = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

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

        // 2b. Low-S malleability check, matching the EVM verifier: for
        //     every valid (r, s) there is a twin (r, n - s) that verifies
        //     for the same message, so accepting high-S would let an
        //     attacker mint a second distinct-on-disk signature and defeat
        //     any downstream signature-bytes dedup. OpenSSL does NOT
        //     enforce this. Cosmos SDK/Tendermint require canonical low-S,
        //     and Keplr emits normalized signatures, so no legitimate
        //     client is rejected. Degenerate r/s == 0 also rejected.
        if (!self::isCanonicalLowS($sigRaw)) {
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
     * Is this raw 64-byte (r||s) signature canonical: r != 0, s != 0,
     * and s <= n/2 (low-S)? See the malleability comment in verify().
     *
     * @param string $rs 64 raw bytes: 32 bytes r + 32 bytes s
     */
    private static function isCanonicalLowS(string $rs): bool {
        if (strlen($rs) !== 64 || !extension_loaded('gmp')) {
            // gmp is a hard boot requirement for this plugin; if it is
            // somehow absent here, fail closed rather than skip the check.
            return false;
        }

        $r = gmp_init(bin2hex(substr($rs, 0, 32)), 16);
        $s = gmp_init(bin2hex(substr($rs, 32, 32)), 16);

        if (gmp_cmp($r, 0) === 0 || gmp_cmp($s, 0) === 0) {
            return false;
        }

        $halfN = gmp_div_q(gmp_init(self::SECP256K1_N, 16), 2);
        return gmp_cmp($s, $halfN) <= 0;
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
     * Bech32 HRPs whose chains derive the account address the Ethermint
     * (Ethereum) way — Keccak-256 of the uncompressed secp256k1 pubkey,
     * last 20 bytes — instead of the standard Cosmos SHA-256→RIPEMD-160.
     *
     * Injective signs with `eth_secp256k1`; Keplr still produces an
     * ADR-036 secp256k1 signature (so the signature check is unchanged),
     * but the address↔pubkey binding uses the Ethereum hash. Deriving the
     * standard Cosmos address for these chains yields the WRONG address
     * and every signature is rejected at the ownership check.
     *
     * Other Ethermint/EVM-Cosmos chains (Evmos `evmos`, Dymension `dym`,
     * etc.) share this derivation; add their HRPs here if/when onboarded.
     *
     * @var array<string, true>
     */
    private const ETHERMINT_HRPS = [
        'inj' => true,
    ];

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

        if (isset(self::ETHERMINT_HRPS[$hrp])) {
            $hash20 = self::ethermintHash160($pubKeyRaw);
            if ($hash20 === null) {
                return '';
            }
        } else {
            // Cosmos address = RIPEMD160(SHA256(compressed_pubkey))
            $sha256Hash = hash('sha256', $pubKeyRaw, true);
            $hash20     = hash('ripemd160', $sha256Hash, true);
        }

        // Convert the 20-byte hash from 8-bit groups to 5-bit groups for bech32.
        $converted = self::convertBits($hash20, 8, 5, true);
        if ($converted === null) {
            return '';
        }

        return self::bech32Encode($hrp, $converted);
    }

    /**
     * Ethermint address bytes: Keccak-256 of the 64-byte UNCOMPRESSED
     * public key (x‖y, no 0x04 prefix), keeping the last 20 bytes — the
     * same scheme Ethereum uses, just bech32-encoded downstream instead
     * of hex. Returns null if the compressed point can't be decompressed.
     *
     * @param string $pubKeyRaw 33-byte compressed secp256k1 public key.
     * @return string|null 20 raw bytes, or null on failure.
     */
    private static function ethermintHash160(string $pubKeyRaw): ?string
    {
        $uncompressed = self::decompressPubKey($pubKeyRaw);
        if ($uncompressed === null) {
            return null;
        }
        // Keccak256::hash(..., true) returns 32 raw bytes; take the last 20.
        $keccak = Keccak256::hash($uncompressed, true);
        if (strlen($keccak) !== 32) {
            return null;
        }
        return substr($keccak, 12);
    }

    /**
     * Decompress a 33-byte compressed secp256k1 public key to its 64-byte
     * uncompressed X‖Y form (no 0x04 prefix). Solves y² = x³ + 7 (mod p)
     * and picks the root whose parity matches the compression prefix
     * (0x02 = even y, 0x03 = odd y). Pure GMP — no external libraries.
     *
     * @param string $compressed 33 raw bytes (prefix byte + 32-byte X).
     * @return string|null 64 raw bytes (X‖Y), or null on invalid input.
     */
    private static function decompressPubKey(string $compressed): ?string
    {
        if (strlen($compressed) !== 33 || !extension_loaded('gmp')) {
            return null;
        }
        $prefix = ord($compressed[0]);
        if ($prefix !== 0x02 && $prefix !== 0x03) {
            return null;
        }

        // secp256k1 field prime.
        $p = gmp_init('fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f', 16);
        $x = gmp_init(bin2hex(substr($compressed, 1)), 16);
        if (gmp_cmp($x, $p) >= 0) {
            return null;
        }

        // rhs = x^3 + 7 (mod p)
        $rhs = gmp_mod(gmp_add(gmp_powm($x, gmp_init(3), $p), gmp_init(7)), $p);
        // p ≡ 3 (mod 4) ⇒ sqrt = rhs^((p+1)/4) mod p
        $exp = gmp_div_q(gmp_add($p, gmp_init(1)), gmp_init(4));
        $y   = gmp_powm($rhs, $exp, $p);

        // Reject if y is not a real square root (point not on curve).
        if (gmp_cmp(gmp_powm($y, gmp_init(2), $p), $rhs) !== 0) {
            return null;
        }

        // Match parity to the prefix; flip to p - y otherwise.
        $wantOdd = ($prefix === 0x03);
        $isOdd   = (gmp_intval(gmp_mod($y, gmp_init(2))) === 1);
        if ($wantOdd !== $isOdd) {
            $y = gmp_sub($p, $y);
        }

        $xHex = str_pad(gmp_strval($x, 16), 64, '0', STR_PAD_LEFT);
        $yHex = str_pad(gmp_strval($y, 16), 64, '0', STR_PAD_LEFT);
        $bin  = hex2bin($xHex . $yHex);

        return $bin === false ? null : $bin;
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
