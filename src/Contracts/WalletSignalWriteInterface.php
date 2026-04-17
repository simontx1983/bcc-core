<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write access to wallet-derived trust scoring data.
 *
 * Implemented by bcc-onchain-signals (writes to bcc_onchain_signals).
 * Used by bcc-trust-engine to persist trust_boost, fraud_reduction, and
 * role after blockchain RPC role checks — without direct cross-plugin
 * table access.
 */
interface WalletSignalWriteInterface
{
    /**
     * Upsert trust-scoring data for a wallet.
     *
     * @param int    $userId
     * @param string $chain           Chain slug (e.g. 'ethereum', 'solana').
     * @param string $walletAddress
     * @param string $role            'creator'|'team'|'holder'|'none'|'pending'
     * @param float  $trustBoost
     * @param int    $fraudReduction
     * @param string $contractAddress
     * @param array<string, mixed> $extra Additional metadata.
     */
    public function upsertTrustSignal(
        int    $userId,
        string $chain,
        string $walletAddress,
        string $role,
        float  $trustBoost,
        int    $fraudReduction,
        string $contractAddress = '',
        array  $extra = []
    ): void;

    /**
     * Save NFT collection metadata and recalculated trust boost.
     *
     * @param list<array<string, mixed>> $collections
     */
    public function saveCollections(
        int    $userId,
        string $chain,
        string $walletAddress,
        array  $collections,
        float  $trustBoost
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
     * Sum trust_boost across all chains for a user.
     */
    public function getTotalTrustBoost(int $userId): float;

    /**
     * Delete all signal rows for a user (account cleanup).
     */
    public function deleteForUser(int $userId): void;
}
