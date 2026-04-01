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

    /**
     * Verify a Solana wallet signature.
     *
     * Phantom's signMessage() signs the raw UTF-8 bytes of the message
     * with Ed25519 and returns base58-encoded signature + public key.
     *
     * @param string $message   Plain-text nonce that was signed
     * @param string $signature Base58-encoded 64-byte Ed25519 signature
     * @param string $address   Base58-encoded Solana public key (wallet address)
     * @return bool
     */
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

        if (strlen($sigBytes) !== SODIUM_CRYPTO_SIGN_BYTES) { // 64 bytes
            return false;
        }

        if (strlen($pubBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) { // 32 bytes
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

    /**
     * Decode a Base58 string to raw binary.
     *
     * Uses the Bitcoin/Solana alphabet:
     *   123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz
     *
     * @param  string      $input Base58 string
     * @return string|null Raw binary bytes, or null on invalid input
     */
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

        // Prepend zero bytes for leading '1' characters in input
        $leadingZeros = 0;
        for ($i = 0; $i < $len && $input[$i] === '1'; $i++) {
            $leadingZeros++;
        }

        return str_repeat("\x00", $leadingZeros) . $bytes;
    }
}
