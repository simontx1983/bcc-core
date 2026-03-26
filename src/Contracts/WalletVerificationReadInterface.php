<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to wallet verification data.
 *
 * Consumer plugins use this interface via ServiceLocator to check
 * wallet status without querying trust tables directly.
 */
interface WalletVerificationReadInterface
{
    /**
     * Get all active wallet connections for a user, keyed by chain.
     *
     * @param int $userId WordPress user ID.
     * @return array<string, string[]> ['ethereum' => ['0xABC…'], 'solana' => ['abc…'], …]
     */
    public function getWalletsForUser(int $userId): array;

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
     * Common types: 'github', 'x' (Twitter/X).
     *
     * @param int    $userId WordPress user ID.
     * @param string $type   Verification type key (e.g. 'github').
     * @return bool
     */
    public function hasVerification(int $userId, string $type): bool;

    /**
     * Get user IDs that have active wallet verifications, paginated.
     *
     * Used by cron jobs that need to discover all users with wallets.
     *
     * @param string[] $chains  Chain names to filter (e.g. ['ethereum', 'solana']).
     * @param int      $limit   Max results per page.
     * @param int      $offset  Offset for pagination.
     * @return int[] Array of user IDs.
     */
    public function getUserIdsWithWallets(array $chains, int $limit = 100, int $offset = 0): array;
}
