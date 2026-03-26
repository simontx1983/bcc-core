<?php
/**
 * Solana Signature Verifier
 *
 * Verifies a Solana wallet signature produced by Phantom's signMessage().
 * Solana uses Ed25519 — PHP's libsodium extension provides native support.
 *
 * Requires: sodium extension (bundled with PHP 7.2+).
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

class SolanaSignatureVerifier {

    public static function verify(string $message, string $signature, string $address): bool {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-core] SolanaVerifier: sodium extension required', []);
            }
            return false;
        }

        $sigBytes = self::base58Decode($signature);
        $pubBytes = self::base58Decode($address);

        if ($sigBytes === null || $pubBytes === null) {
            return false;
        }

        if (strlen($sigBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        if (strlen($pubBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($sigBytes, $message, $pubBytes);
        } catch (\SodiumException $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-core] SolanaVerifier error', ['detail' => $e->getMessage()]);
            }
            return false;
        }
    }

    public static function base58Decode(string $input): ?string {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base     = strlen($alphabet);

        $num = gmp_init(0);
        $len = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) {
                return null;
            }
            $num = gmp_add(gmp_mul($num, gmp_init($base)), gmp_init($pos));
        }

        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }
        $bytes = strlen($hex) > 0 ? hex2bin($hex) : '';

        $leadingZeros = 0;
        for ($i = 0; $i < $len && $input[$i] === '1'; $i++) {
            $leadingZeros++;
        }

        return str_repeat("\x00", $leadingZeros) . $bytes;
    }
}
