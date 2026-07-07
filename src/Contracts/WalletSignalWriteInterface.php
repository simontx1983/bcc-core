<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write access to wallet-derived trust scoring data.
 *
 * Implemented by bcc-trust's Onchain domain (writes to bcc_onchain_signals).
 * Used by bcc-trust's Core domain to persist the wallet role and NFT
 * collection metadata after blockchain RPC checks — without direct
 * cross-plugin table access. (The role-based trust_boost / fraud_reduction
 * columns were removed 2026-07-07 with the dead wallet-role boost.)
 */
interface WalletSignalWriteInterface
{
    /**
     * Upsert the role + presence row for a wallet.
     *
     * @param int    $userId
     * @param string $chain           Chain slug (e.g. 'ethereum', 'solana').
     * @param string $walletAddress
     * @param string $role            'creator'|'team'|'holder'|'none'|'pending'
     * @param string $contractAddress
     * @param array<string, mixed> $extra Additional metadata.
     */
    public function upsertTrustSignal(
        int    $userId,
        string $chain,
        string $walletAddress,
        string $role,
        string $contractAddress = '',
        array  $extra = []
    ): void;

    /**
     * Save NFT collection metadata for a wallet.
     *
     * @param list<array<string, mixed>> $collections
     */
    public function saveCollections(
        int    $userId,
        string $chain,
        string $walletAddress,
        array  $collections
    ): void;

    /**
     * Zero out trust scoring for a disconnected wallet.
     */
    public function disconnectTrustSignal(int $userId, string $chain): void;

    /**
     * Get trust-signal data for a single chain.
     *
     * @return object|null
     */
    public function getTrustSignalForUserChain(int $userId, string $chain): ?object;

    /**
     * Get all trust-signal rows for a user, keyed by chain name.
     *
     * @return array<string, object>
     */
    public function getAllTrustSignalsForUser(int $userId): array;

    /**
     * Delete all signal rows for a user (account cleanup).
     */
    public function deleteForUser(int $userId): void;
}
