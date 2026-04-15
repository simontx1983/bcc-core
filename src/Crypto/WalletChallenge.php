<?php
/**
 * Shared wallet challenge (nonce) generation.
 *
 * Both trust-engine (REST) and onchain-signals (AJAX) controllers should
 * use this class to generate the signing message so that:
 *   1. The message format is identical across entry points
 *   2. Cosmos ADR-036 signing works consistently
 *   3. Changes to the message format happen in one place
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletChallenge
{
    /**
     * Standard prefix for all BCC wallet signing messages.
     */
    public const PREFIX = 'Sign this message to verify your wallet on Blue Collar Crypto. Nonce: ';

    /**
     * Generate a challenge message for wallet signing.
     *
     * @param string $chainSlug Chain identifier embedded in the message
     *                          to prevent cross-chain signature replay.
     * @return array{nonce: string, message: string}
     */
    public static function generate(string $chainSlug = ''): array
    {
        $nonce   = bin2hex(random_bytes(16));
        $chain   = $chainSlug !== '' ? " [{$chainSlug}]" : '';
        $message = self::PREFIX . $nonce . $chain;

        return [
            'nonce'   => $nonce,
            'message' => $message,
        ];
    }
}
