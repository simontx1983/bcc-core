<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\WalletLinkReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-onchain-signals is not active.
 */
final class NullWalletLinkRead implements WalletLinkReadInterface
{
    public function getLinksForUser(int $userId): array
    {
        return [];
    }

    public function hasLink(int $userId, string $chain): bool
    {
        return false;
    }

    public function getUserIdsWithLinks(array $chains, int $limit = 100, int $offset = 0): array
    {
        return [];
    }
}
