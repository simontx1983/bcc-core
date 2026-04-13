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
     * Map chain slugs to their chain_type for verification dispatch.
     *
     * Callers may pass either a chain_type ('evm', 'solana', 'cosmos')
     * or a chain slug ('ethereum', 'polygon', 'osmosis', etc.).
     * This map normalises slugs to the canonical chain_type so the
     * match below always works regardless of what the caller provides.
     */
    public const SLUG_TO_TYPE = [
        // EVM chains
        'ethereum'  => 'evm',
        'polygon'   => 'evm',
        'arbitrum'  => 'evm',
        'optimism'  => 'evm',
        'base'      => 'evm',
        'avalanche' => 'evm',
        'bsc'       => 'evm',
        // Cosmos chains
        'cosmos'    => 'cosmos',
        'osmosis'   => 'cosmos',
        'akash'     => 'cosmos',
        'juno'      => 'cosmos',
        'stargaze'  => 'cosmos',
        // Solana
        'solana'    => 'solana',
    ];

    /**
     * Verify a wallet signature for any supported chain.
     *
     * @param string $chain     Chain type ('evm', 'solana', 'cosmos') or chain slug ('ethereum', 'polygon', etc.)
     * @param string $message   Plain-text message/nonce that was signed
     * @param string $signature Chain-specific encoded signature
     * @param string $address   Wallet address
     * @param array<string, string>  $extra     Chain-specific extra params:
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
        // Normalise: accept both chain slugs and chain_types
        $type = self::SLUG_TO_TYPE[$chain] ?? $chain;

        return match ($type) {
            'evm'    => EthSignatureVerifier::verify($message, $signature, $address),
            'solana' => SolanaSignatureVerifier::verify($message, $signature, $address),
            'cosmos' => CosmosSignatureVerifier::verify(
                $message,
                $signature,
                $address,
                $extra['pub_key']    ?? '',
                $extra['chain_id']   ?? 'cosmoshub-4'
            ),
            default => false,
        };
    }

}
