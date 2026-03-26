<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only access to wallet link data (bcc-onchain-signals).
 *
 * Bridges wallet data stored in bcc_wallet_links so that trust-engine
 * can include AJAX-verified wallets in WalletVerificationReadInterface
 * responses without querying another plugin's tables directly.
 */
interface WalletLinkReadInterface
{
    /**
     * Get all wallet links for a user, keyed by chain slug.
     *
     * @param int $userId WordPress user ID.
     * @return array<string, string[]> ['ethereum' => ['0xABC…'], 'solana' => ['abc…'], …]
     */
    public function getLinksForUser(int $userId): array;

    /**
     * Check whether a user has at least one wallet link on the given chain.
     *
     * @param int    $userId WordPress user ID.
     * @param string $chain  Chain slug (e.g. 'ethereum', 'solana').
     * @return bool
     */
    public function hasLink(int $userId, string $chain): bool;

    /**
     * Get user IDs that have wallet links, paginated.
     *
     * @param string[] $chains  Chain slugs to filter (e.g. ['ethereum', 'solana']).
     * @param int      $limit   Max results per page.
     * @param int      $offset  Offset for pagination.
     * @return int[] Array of user IDs.
     */
    public function getUserIdsWithLinks(array $chains, int $limit = 100, int $offset = 0): array;
}
