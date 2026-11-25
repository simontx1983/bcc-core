<?php
/**
 * Ethereum Signature Verifier
 *
 * Recovers the signer address from an Ethereum personal_sign signature.
 * Uses pure GMP for secp256k1 ecrecover (Secp256k1::recoverPublicKey()), no external libraries.
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

        // Reject non-hex payloads before any numeric parsing: gmp_init(..., 16)
        // throws ValueError on invalid digits (PHP 8), and this string is
        // user-supplied. This guard is also what makes the hexdec() below safe.
        if (!ctype_xdigit($sig)) {
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

        // EIP-2 / BIP-0062 malleability check: reject high-S signatures.
        // For every valid ECDSA signature (r, s) there is a counterpart
        // (r, n - s, v ^ 1) that recovers the same address. Accepting
        // both would let an attacker mint a second distinct-on-disk
        // signature for the same message, bypassing any downstream
        // idempotency / replay-dedup that hashes the signature bytes.
        // Require s <= n/2.
        $sGmp    = gmp_init($s, 16);
        $nGmp    = gmp_init(Secp256k1::N, 16);
        $halfN   = gmp_div_q($nGmp, gmp_init(2, 10));
        if (gmp_cmp($sGmp, $halfN) > 0) {
            return null;
        }
        // Also reject degenerate r/s == 0 (invalid signatures).
        if (gmp_cmp($sGmp, gmp_init(0, 10)) === 0
            || gmp_cmp(gmp_init($r, 16), gmp_init(0, 10)) === 0
        ) {
            return null;
        }

        $msgHash = self::hashMessage($message);

        $pubKey = Secp256k1::recoverPublicKey($msgHash, $v, $r, $s);
        if ($pubKey === null) {
            return null;
        }

        $pubKeyBinary = hex2bin($pubKey);
        if ($pubKeyBinary === false) {
            // recoverPublicKey() always produces 128 hex chars, so this is unreachable
            // in practice. Fail safe in case an upstream change ever violates that.
            return null;
        }
        $hash    = Keccak256::hash($pubKeyBinary, false);
        $address = '0x' . substr($hash, 24);

        return $address;
    }

    private static function hashMessage(string $message): string {
        $prefix  = "\x19Ethereum Signed Message:\n" . strlen($message);
        return Keccak256::hash($prefix . $message, false);
    }
}
