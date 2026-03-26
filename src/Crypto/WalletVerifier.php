<?php
/**
 * Unified wallet signature verification facade.
 *
 * Dispatches to chain-specific verifiers that live alongside this class.
 * All verifiers use standard PHP extensions (GMP, sodium, OpenSSL) — no
 * external Composer dependencies required.
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletVerifier
{
    /**
     * Verify a wallet signature for any supported chain.
     *
     * @param string $chain     'ethereum' | 'evm' | 'solana' | 'cosmos'
     * @param string $message   Plain-text message/nonce that was signed
     * @param string $signature Chain-specific encoded signature
     * @param string $address   Wallet address
     * @param array  $extra     Chain-specific extra params:
     *                          Cosmos: ['pub_key' => base64, 'chain_id' => string, 'signed_doc' => json]
     * @return bool
     */
    public static function verify(
        string $chain,
        string $message,
        string $signature,
        string $address,
        array $extra = []
    ): bool {
        return match ($chain) {
            'ethereum', 'evm' => EthSignatureVerifier::verify($message, $signature, $address),
            'solana'   => SolanaSignatureVerifier::verify($message, $signature, $address),
            'cosmos'   => CosmosSignatureVerifier::verify(
                $message,
                $signature,
                $address,
                $extra['pub_key']    ?? '',
                $extra['chain_id']   ?? 'cosmoshub-4',
                $extra['signed_doc'] ?? ''
            ),
            default => false,
        };
    }
}
