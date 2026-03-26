<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write access to the canonical wallet link store (bcc_wallet_links).
 *
 * Used by trust-engine to write wallet records to onchain-signals' table
 * via contract instead of direct cross-plugin DB access.
 */
interface WalletLinkWriteInterface
{
    /**
     * Insert a verified wallet link.
     *
     * Returns the new wallet_link_id, or 0 on failure.
     * If the wallet already exists (same user + chain + address), returns
     * the existing row's ID without inserting a duplicate.
     *
     * @param int    $userId        WordPress user ID.
     * @param string $chainSlug     Chain slug (e.g. 'ethereum', 'solana', 'cosmos').
     * @param string $walletAddress Wallet address.
     * @param int    $postId        Associated post/page ID (0 if none).
     * @param string $walletType    'user' | 'treasury' | 'validator' etc.
     * @return int   wallet_link_id or 0 on failure.
     */
    public function linkWallet(
        int $userId,
        string $chainSlug,
        string $walletAddress,
        int $postId = 0,
        string $walletType = 'user'
    ): int;

    /**
     * Remove a wallet link (e.g. on disconnect).
     *
     * @param int $userId        WordPress user ID.
     * @param string $chainSlug  Chain slug.
     * @param string $walletAddress Wallet address.
     * @return bool True if deleted.
     */
    public function unlinkWallet(int $userId, string $chainSlug, string $walletAddress): bool;
}
