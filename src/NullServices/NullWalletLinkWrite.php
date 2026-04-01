<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\WalletLinkWriteInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-onchain-signals is not active.
 */
final class NullWalletLinkWrite implements WalletLinkWriteInterface
{
    public function linkWallet(int $userId, string $chainSlug, string $walletAddress, int $postId = 0, string $walletType = 'user'): int
    {
        return 0;
    }

    public function unlinkWallet(int $userId, string $chainSlug, string $walletAddress): bool
    {
        return false;
    }
}
