<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\WalletSignalWriteInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * All writes are silently discarded; reads return empty/zero defaults.
 * The trust-signal pipeline (Onchain validators / NFT holdings /
 * delegations) silently drops on the way to bcc_onchain_signals when
 * this fallback activates — sustained activation = chain-derived
 * trust bonuses are not being applied.
 */
final class NullWalletSignalWrite implements WalletSignalWriteInterface
{
    public function upsertTrustSignal(
        int    $userId,
        string $chain,
        string $walletAddress,
        string $role,
        float  $trustBoost,
        int    $fraudReduction,
        string $contractAddress = '',
        array  $extra = []
    ): void {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_signal_write', 'activation');
    }

    public function saveCollections(
        int    $userId,
        string $chain,
        string $walletAddress,
        array  $collections,
        float  $trustBoost
    ): void {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_signal_write', 'activation');
    }

    public function disconnectTrustSignal(int $userId, string $chain): void
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_signal_write', 'activation');
    }

    public function getTrustSignalForUserChain(int $userId, string $chain): ?object
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_signal_write', 'activation');
        return null;
    }

    public function getAllTrustSignalsForUser(int $userId): array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_signal_write', 'activation');
        return [];
    }

    public function deleteForUser(int $userId): void
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_signal_write', 'activation');
    }
}
