<?php
/**
 * Ethereum Signature Verifier
 *
 * Recovers the signer address from an Ethereum personal_sign signature.
 * Uses pure GMP for secp256k1 ecrecover (no external libraries required).
 *
 * Requires: GMP extension (BCMath is NOT needed — we use GMP throughout).
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class EthSignatureVerifier {

    private const P  = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
    private const N  = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    private const GX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private const GY = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

    /** Maximum message length to prevent DoS via Keccak256 hash flooding. */
    private const MAX_MESSAGE_LENGTH = 1024;

    public static function verify(string $message, string $signature, string $address): bool {
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return false;
        }
        $recovered = self::recoverAddress($message, $signature);
        if ($recovered === null) {
            return false;
        }
        return hash_equals(strtolower($recovered), strtolower($address));
    }

    private static function recoverAddress(string $message, string $signature): ?string {
        if (!extension_loaded('gmp')) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-core] EthVerifier: GMP extension required', []);
            }
            return null;
        }

        // Strip 0x prefix (use substr, NOT ltrim — ltrim strips chars from a set,
        // so '0x00abc...' would incorrectly eat the leading zero byte of r).
        $sig = trim($signature);
        if (substr($sig, 0, 2) === '0x' || substr($sig, 0, 2) === '0X') {
            $sig = substr($sig, 2);
        }

        if (strlen($sig) !== 130) {
            return null;
        }

        $r = substr($sig, 0, 64);
        $s = substr($sig, 64, 64);
        $v = hexdec(substr($sig, 128, 2));

        if ($v >= 27) {
            $v -= 27;
        }
        if ($v !== 0 && $v !== 1) {
            return null;
        }

        $msgHash = self::hashMessage($message);

        $pubKey = self::ecrecover($msgHash, $v, $r, $s);
        if ($pubKey === null) {
            return null;
        }

        $hash    = Keccak256::hash(hex2bin($pubKey), false);
        $address = '0x' . substr($hash, 24);

        return $address;
    }

    private static function hashMessage(string $message): string {
        $prefix  = "\x19Ethereum Signed Message:\n" . strlen($message);
        return Keccak256::hash($prefix . $message, false);
    }

    /**
     * secp256k1 ecrecover.
     *
     * Recovers the uncompressed public key (128 hex chars, no 04 prefix)
     * from (msgHash, v, r, s).
     *
     * @param string $msgHash 32-byte hash as 64-char hex string
     * @param int    $v       Recovery id (0 or 1)
     * @param string $r       64-char hex
     * @param string $s       64-char hex
     * @return string|null    128-char hex (x‖y of uncompressed pubkey) or null
     */
    private static function ecrecover(string $msgHash, int $v, string $r, string $s): ?string {
        $p  = gmp_init(self::P,  16);
        $n  = gmp_init(self::N,  16);
        $gx = gmp_init(self::GX, 16);
        $gy = gmp_init(self::GY, 16);

        $rGmp = gmp_init($r, 16);
        $sGmp = gmp_init($s, 16);
        $zGmp = gmp_init($msgHash, 16);

        // x = r + v*n  (for most signatures v*n < p so x == r)
        $x = gmp_add($rGmp, gmp_mul(gmp_init($v), $n));
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
        $sum   = self::pointAdd($sR, $negZG, $p);
        $pub   = self::pointMul($sum, $rInv, $p, $n);

        if ($pub === null) {
            return null;
        }

        // Return as 128-char hex (x || y, each zero-padded to 32 bytes)
        return str_pad(gmp_strval($pub[0], 16), 64, '0', STR_PAD_LEFT)
             . str_pad(gmp_strval($pub[1], 16), 64, '0', STR_PAD_LEFT);
    }

    /**
     * Recover y from x on secp256k1: y² = x³ + 7 (mod p).
     * Choose the y whose parity matches $v (even y ↔ v=0, odd y ↔ v=1).
     */
    private static function recoverY(\GMP $x, \GMP $p, int $v): ?\GMP {
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
     * @return array{\GMP, \GMP}      [x, y]
     */
    private static function pointAdd(array $p1, array $p2, \GMP $mod): ?array {
        [$x1, $y1] = $p1;
        [$x2, $y2] = $p2;

        // P + (-P) = point at infinity
        if (gmp_cmp($x1, $x2) === 0 && gmp_cmp($y1, $y2) !== 0) {
            return null;
        }

        if (gmp_cmp($x1, $x2) === 0 && gmp_cmp($y1, $y2) === 0) { // Point doubling
            $lam = gmp_mod(
                gmp_mul(
                    gmp_mul(gmp_init(3), gmp_powm($x1, gmp_init(2), $mod)),
                    gmp_invert(gmp_mod(gmp_mul(gmp_init(2), $y1), $mod), $mod)
                ),
                $mod
            );
        } else {
            $dy  = gmp_mod(gmp_sub($y2, $y1), $mod);
            $dx  = gmp_mod(gmp_sub($x2, $x1), $mod);
            $inv = gmp_invert(gmp_mod($dx, $mod), $mod);
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
    private static function pointMul(array $point, \GMP $k, \GMP $p, \GMP $n): ?array {
        $k = gmp_mod($k, $n);
        if (gmp_cmp($k, gmp_init(0)) === 0) {
            return null;
        }
        $result = null;
        $addend = $point;
        $bits = gmp_strval($k, 2);
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
