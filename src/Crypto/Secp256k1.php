<?php
/**
 * secp256k1 primitives shared by the wallet verifiers.
 *
 * One home for the curve code the Ethereum, Cosmos ADR-036 and Cosmos
 * transaction verifiers use, so there is ONE implementation of each:
 *   - verifySha256(): ECDSA over SHA-256 of a message, through OpenSSL;
 *   - verifyDigest(): ECDSA over a 32-byte digest the caller computed
 *     (Keccak-256 for Ethermint keys), pure GMP;
 *   - recoverPublicKey(): Ethereum-style public-key recovery, pure GMP;
 *   - decompress() and isCanonicalLowS().
 *
 * Requires ext-gmp and ext-openssl (both hard requirements of this plugin).
 * Every function fails CLOSED: malformed input is false or null, never an
 * exception.
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class Secp256k1
{
    /** Field prime p. */
    public const P = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';

    /** Group order n. */
    public const N = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    private const GX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private const GY = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

    /**
     * ECDSA verification of a compact (r||s) signature over SHA-256 of
     * $message, with a 33-byte compressed public key. OpenSSL hashes the
     * message itself. A high-S signature is refused (see isCanonicalLowS()).
     */
    public static function verifySha256(string $message, string $rs, string $compressedPubKey): bool
    {
        if (strlen($rs) !== 64 || strlen($compressedPubKey) !== 33 || !self::isCanonicalLowS($rs)) {
            return false;
        }
        $der = self::rsToAsn1($rs);
        if ($der === null) {
            return false;
        }
        $key = self::importCompressedPubKey($compressedPubKey);
        if ($key === false || $key === null) {
            return false;
        }

        return openssl_verify($message, $der, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * ECDSA verification of a compact (r||s) signature over a 32-byte
     * $digest the caller has already computed, with a 33-byte compressed
     * public key. Textbook SEC 1 §4.1.4, pure GMP: r and s nonzero, s low
     * (isCanonicalLowS(), so s < n), the key a point on the curve, then
     * X = (z·s⁻¹)·G + (r·s⁻¹)·Q and accept iff X is not infinity and
     * X.x mod n == r. (An r ≥ n can never equal X.x mod n, which is below
     * n, so the range check on r is that comparison.)
     *
     * For a SHA-256 digest this agrees with verifySha256(); the tests hold
     * the two to that.
     */
    public static function verifyDigest(string $digest, string $rs, string $compressedPubKey): bool
    {
        if (strlen($digest) !== 32 || strlen($rs) !== 64 || !self::isCanonicalLowS($rs)) {
            return false;
        }
        $q = self::decompress($compressedPubKey);
        if ($q === null) {
            return false;
        }

        $n = gmp_init(self::N, 16);
        $p = gmp_init(self::P, 16);
        $r = gmp_init(bin2hex(substr($rs, 0, 32)), 16);
        $s = gmp_init(bin2hex(substr($rs, 32, 32)), 16);
        $z = gmp_init(bin2hex($digest), 16);

        $w = gmp_invert($s, $n);
        if ($w === false) {
            return false;
        }
        $u1 = gmp_mod(gmp_mul($z, $w), $n);
        $u2 = gmp_mod(gmp_mul($r, $w), $n);

        $qPoint = [gmp_init(bin2hex(substr($q, 0, 32)), 16), gmp_init(bin2hex(substr($q, 32, 32)), 16)];
        $g      = [gmp_init(self::GX, 16), gmp_init(self::GY, 16)];

        $a = self::pointMul($g, $u1, $p, $n);      // null when u1 = 0
        $b = self::pointMul($qPoint, $u2, $p, $n); // null when u2 = 0 (only for r ≡ 0 mod n)
        if ($b === null) {
            return false;
        }
        $x = $a === null ? $b : self::pointAdd($a, $b, $p);
        if ($x === null) {
            return false; // the point at infinity
        }

        return gmp_cmp(gmp_mod($x[0], $n), $r) === 0;
    }

    /**
     * secp256k1 public-key recovery (Ethereum ecrecover).
     *
     * Recovers the uncompressed public key (128 hex chars, no 04 prefix)
     * from (msgHash, recovery id, r, s).
     *
     * @param string $msgHash 32-byte hash as a 64-char hex string
     * @param int    $v       Recovery id (0 or 1): R's Y parity
     * @param string $r       64-char hex
     * @param string $s       64-char hex
     * @return string|null    128-char hex (x‖y of the uncompressed key) or null
     */
    public static function recoverPublicKey(string $msgHash, int $v, string $r, string $s): ?string
    {
        $p  = gmp_init(self::P,  16);
        $n  = gmp_init(self::N,  16);
        $gx = gmp_init(self::GX, 16);
        $gy = gmp_init(self::GY, 16);

        $rGmp = gmp_init($r, 16);
        $sGmp = gmp_init($s, 16);
        $zGmp = gmp_init($msgHash, 16);

        // The X-coordinate of the ephemeral point R is r. The recovery id
        // ($v, normalized to 0/1 by the caller) is used ONLY to select R's Y
        // parity, below in recoverY(). It is NOT an X candidate: the
        // additional "x = r + n" case belongs to a two-bit recovery id, which
        // Ethereum personal_sign (v = 27/28 → 0/1) never encodes — it carries
        // parity only. So x is always r here. (An earlier version added $v*$n
        // to r, which for v = 1 made x = r + n; since n ≈ p that almost
        // always exceeded p and nulled the recovery, rejecting every v=28
        // signature.)
        $x = $rGmp;
        if (gmp_cmp($x, $p) >= 0) {
            return null;
        }

        $y = self::recoverY($x, $p, $v);
        if ($y === null) {
            return null;
        }

        // rInv = modular inverse of r mod n
        $rInv = gmp_invert($rGmp, $n);
        if ($rInv === false) {
            return null;
        }

        $negZ = gmp_mod(gmp_neg($zGmp), $n);

        // pubKey = rInv * (s*R - z*G)
        //        = rInv * s * R + rInv * (-z) * G
        $sR    = self::pointMul([$x, $y], $sGmp, $p, $n);
        $negZG = self::pointMul([$gx, $gy], $negZ, $p, $n);
        if ($sR === null || $negZG === null) {
            return null;
        }
        $sum = self::pointAdd($sR, $negZG, $p);
        if ($sum === null) {
            return null;
        }
        $pub = self::pointMul($sum, $rInv, $p, $n);

        if ($pub === null) {
            return null;
        }

        // Return as 128-char hex (x || y, each zero-padded to 32 bytes)
        return str_pad(gmp_strval($pub[0], 16), 64, '0', STR_PAD_LEFT)
             . str_pad(gmp_strval($pub[1], 16), 64, '0', STR_PAD_LEFT);
    }

    /**
     * Is this raw 64-byte (r||s) signature canonical: r != 0, s != 0, and
     * s <= n/2 (low-S)?
     *
     * Malleability: for every valid (r, s) there is a twin (r, n - s) that
     * verifies for the same message, so accepting high-S would let anyone
     * mint a second distinct-on-disk signature. OpenSSL does NOT enforce
     * this. Cosmos SDK/Tendermint and go-ethereum require low-S, and wallets
     * emit normalized signatures, so no legitimate signer is refused.
     *
     * @param string $rs 64 raw bytes: 32 bytes r + 32 bytes s
     */
    public static function isCanonicalLowS(string $rs): bool
    {
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

        $halfN = gmp_div_q(gmp_init(self::N, 16), 2);
        return gmp_cmp($s, $halfN) <= 0;
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
    public static function decompress(string $compressed): ?string
    {
        if (strlen($compressed) !== 33 || !extension_loaded('gmp')) {
            return null;
        }
        $prefix = ord($compressed[0]);
        if ($prefix !== 0x02 && $prefix !== 0x03) {
            return null;
        }

        $p = gmp_init(self::P, 16);
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
     * Convert raw 64-byte (r||s) signature to DER-encoded ASN.1 for OpenSSL.
     *
     * @param  string      $rs 64 raw bytes: 32 bytes r + 32 bytes s
     * @return string|null DER binary or null on failure
     */
    private static function rsToAsn1(string $rs): ?string
    {
        if (strlen($rs) !== 64) {
            return null;
        }
        $r = substr($rs, 0, 32);
        $s = substr($rs, 32, 32);

        // Strip leading zeros, but keep at least one byte
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if (strlen($r) === 0) {
            $r = "\x00";
        }
        if (strlen($s) === 0) {
            $s = "\x00";
        }

        // If MSB is set, prepend 0x00 to indicate positive integer in DER
        if (ord($r[0]) >= 0x80) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) >= 0x80) {
            $s = "\x00" . $s;
        }

        $rDer = "\x02" . chr(strlen($r)) . $r;
        $sDer = "\x02" . chr(strlen($s)) . $s;

        return "\x30" . chr(strlen($rDer) + strlen($sDer)) . $rDer . $sDer;
    }

    /**
     * Import a compressed secp256k1 public key (33 bytes) as an OpenSSL key.
     * Wraps the raw EC point in a SubjectPublicKeyInfo DER structure and
     * converts to PEM for OpenSSL import.
     *
     * @param  string $compressed 33 raw bytes
     * @return \OpenSSLAsymmetricKey|false|null
     */
    private static function importCompressedPubKey(string $compressed)
    {
        if (strlen($compressed) !== 33) {
            return null;
        }

        $oidEcPublicKey = "\x2a\x86\x48\xce\x3d\x02\x01";
        $oidSecp256k1   = "\x2b\x81\x04\x00\x0a";

        $algSeq = "\x30" . chr(2 + strlen($oidEcPublicKey) + 2 + strlen($oidSecp256k1))
                . "\x06" . chr(strlen($oidEcPublicKey)) . $oidEcPublicKey
                . "\x06" . chr(strlen($oidSecp256k1)) . $oidSecp256k1;

        $bitString = "\x03" . chr(1 + strlen($compressed)) . "\x00" . $compressed;

        $spki = "\x30" . chr(strlen($algSeq) + strlen($bitString)) . $algSeq . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($spki), 64, "\n")
             . "-----END PUBLIC KEY-----\n";

        return openssl_get_publickey($pem);
    }

    /**
     * Recover y from x on secp256k1: y² = x³ + 7 (mod p).
     * Choose the y whose parity matches $v (even y ↔ v=0, odd y ↔ v=1).
     */
    private static function recoverY(\GMP $x, \GMP $p, int $v): ?\GMP
    {
        $rhs = gmp_mod(gmp_add(gmp_powm($x, gmp_init(3), $p), gmp_init(7)), $p);
        // p ≡ 3 (mod 4) so sqrt = rhs^((p+1)/4) mod p
        $exp = gmp_div_q(gmp_add($p, gmp_init(1)), gmp_init(4));
        $y   = gmp_powm($rhs, $exp, $p);
        if (gmp_cmp(gmp_powm($y, gmp_init(2), $p), $rhs) !== 0) {
            return null;
        }
        $yParity = gmp_intval(gmp_mod($y, gmp_init(2)));
        if ($yParity !== $v) {
            $y = gmp_mod(gmp_neg($y), $p);
        }
        return $y;
    }

    /**
     * Elliptic curve point addition on secp256k1 (affine coordinates).
     *
     * @param  array{\GMP, \GMP} $p1  [x, y]
     * @param  array{\GMP, \GMP} $p2  [x, y]
     * @param  \GMP              $mod Field prime p
     * @return array{\GMP, \GMP}|null [x, y], or null for the point at infinity
     */
    private static function pointAdd(array $p1, array $p2, \GMP $mod): ?array
    {
        [$x1, $y1] = $p1;
        [$x2, $y2] = $p2;

        // P + (-P) = point at infinity
        if (gmp_cmp($x1, $x2) === 0 && gmp_cmp($y1, $y2) !== 0) {
            return null;
        }

        if (gmp_cmp($x1, $x2) === 0 && gmp_cmp($y1, $y2) === 0) { // Point doubling
            // 2·y1 ≡ 0 (mod p) only for points of order 2 (y=0). secp256k1 has
            // no such rational points, but a crafted signature could steer here.
            $twoY1Inv = gmp_invert(gmp_mod(gmp_mul(gmp_init(2), $y1), $mod), $mod);
            if ($twoY1Inv === false) {
                return null;
            }
            $lam = gmp_mod(
                gmp_mul(
                    gmp_mul(gmp_init(3), gmp_powm($x1, gmp_init(2), $mod)),
                    $twoY1Inv
                ),
                $mod
            );
        } else {
            $dy = gmp_mod(gmp_sub($y2, $y1), $mod);
            $dx = gmp_mod(gmp_sub($x2, $x1), $mod);
            // $dx cannot be 0 here (x1 != x2 per the branch condition), but
            // guard anyway: any invert failure would fatal on the next gmp_mul.
            $inv = gmp_invert(gmp_mod($dx, $mod), $mod);
            if ($inv === false) {
                return null;
            }
            $lam = gmp_mod(gmp_mul($dy, $inv), $mod);
        }
        $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_powm($lam, gmp_init(2), $mod), $x1), $x2), $mod);
        $y3 = gmp_mod(gmp_sub(gmp_mul($lam, gmp_sub($x1, $x3)), $y1), $mod);
        return [gmp_mod($x3, $mod), gmp_mod($y3, $mod)];
    }

    /**
     * Elliptic curve scalar multiplication using double-and-add.
     *
     * @param  array{\GMP, \GMP}      $point [x, y]
     * @param  \GMP                   $k     Scalar
     * @param  \GMP                   $p     Field prime
     * @param  \GMP                   $n     Curve order (used for k mod n)
     * @return array{\GMP, \GMP}|null        [x, y] or null if k == 0
     */
    private static function pointMul(array $point, \GMP $k, \GMP $p, \GMP $n): ?array
    {
        $k = gmp_mod($k, $n);
        if (gmp_cmp($k, gmp_init(0)) === 0) {
            return null;
        }
        $result = null;
        $addend = $point;
        $bits   = gmp_strval($k, 2);
        for ($i = strlen($bits) - 1; $i >= 0; $i--) {
            if ($bits[$i] === '1') {
                if ($result === null) {
                    $result = $addend;
                } else {
                    $result = self::pointAdd($result, $addend, $p);
                    if ($result === null) {
                        return null; // Point at infinity
                    }
                }
            }
            $addend = self::pointAdd($addend, $addend, $p);
            if ($addend === null) {
                return $result; // Addend reached infinity
            }
        }
        return $result;
    }
}
