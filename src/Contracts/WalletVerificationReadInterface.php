<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only "is this user verified?" checks.
 *
 * Consumer plugins use this interface via ServiceLocator to answer narrow
 * yes/no verification questions without querying trust tables directly.
 *
 * For wallet *identity* data (addresses, chains, lists), use
 * {@see WalletLinkReadInterface} directly — that interface is the canonical
 * wallet store owned by bcc-onchain-signals. Previous methods `getWalletsForUser`
 * and `getUserIdsWithWallets` were thin passthroughs to the corresponding
 * WalletLinkRead methods and have been removed to eliminate duplication.
 */
interface WalletVerificationReadInterface
{
    /**
     * Check whether a user has at least one verified (active) wallet.
     *
     * @param int $userId WordPress user ID.
     * @return bool
     */
    public function hasVerifiedWallet(int $userId): bool;

    /**
     * Check whether a user has an active verification of the given type.
     *
     * Common types: 'github', 'x' (Twitter/X). Wallet-typed checks
     * (e.g. 'wallet_ethereum') are delegated to WalletLinkReadInterface
     * by the implementation.
     *
     * @param int    $userId WordPress user ID.
     * @param string $type   Verification type key (e.g. 'github').
     * @return bool
     */
    public function hasVerification(int $userId, string $type): bool;
}
