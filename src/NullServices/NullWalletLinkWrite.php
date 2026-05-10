<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\WalletLinkWriteInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * Returns 0 / false so callers can detect the silent-write and either
 * log or queue a retry. Sustained activation = wallet-link writes are
 * silently dropping; users complete a wallet auth flow but the link
 * doesn't persist.
 */
final class NullWalletLinkWrite implements WalletLinkWriteInterface
{
    public function linkWallet(int $userId, string $chainSlug, string $walletAddress, int $postId = 0, string $walletType = 'user', string $label = ''): int
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_link_write', 'activation');
        return 0;
    }

    public function unlinkWallet(int $userId, string $chainSlug, string $walletAddress): bool
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_link_write', 'activation');
        return false;
    }
}
