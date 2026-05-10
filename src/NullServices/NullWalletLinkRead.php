<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\WalletLinkReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * Fail-open: returns empty link sets. Callers that gate features on
 * verified-wallet status (e.g., FeatureAccessService) treat "no links"
 * as "no verified wallet" — UX-degraded but not security-broken.
 */
final class NullWalletLinkRead implements WalletLinkReadInterface
{
    public function getLinksForUser(int $userId): array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_link_read', 'activation');
        return [];
    }

    public function hasLink(int $userId, string $chain): bool
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_link_read', 'activation');
        return false;
    }

    public function getUserIdsWithLinks(array $chains, int $limit = 100, int $offset = 0): array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_wallet_link_read', 'activation');
        return [];
    }
}
