<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\WalletSignalWriteInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-onchain-signals is not active.
 *
 * All writes are silently discarded; reads return empty/zero defaults.
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
    ): void {}

    public function saveCollections(
        int    $userId,
        string $chain,
        string $walletAddress,
        array  $collections,
        float  $trustBoost
    ): void {}

    public function disconnectTrustSignal(int $userId, string $chain): void {}

    public function getTrustSignalForUserChain(int $userId, string $chain): ?object
    {
        return null;
    }

    public function getAllTrustSignalsForUser(int $userId): array
    {
        return [];
    }

    public function getTotalTrustBoost(int $userId): float
    {
        return 0.0;
    }

    public function deleteForUser(int $userId): void {}
}
