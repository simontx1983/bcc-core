<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\TrustReadServiceInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fail-safe implementation returned when bcc-trust-engine is not active.
 *
 * SECURITY: Methods that gate access return RESTRICTIVE defaults
 * (fail-closed). Methods that return data return empty values.
 *
 * This ensures that when the trust engine is down:
 *   - isSuspended() returns TRUE → all non-admin users are blocked
 *   - getEligiblePanelistUserIds() returns [] → no panelists available
 *   - Data queries return empty results (no false data)
 */
final class NullTrustReadService implements TrustReadServiceInterface
{
    public function getVoteById(int $voteId): ?array
    {
        return null;
    }

    public function getActiveVotesForPage(int $pageId, int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function countActiveVotesForPage(int $pageId): int
    {
        return 0;
    }

    public function getVotesByIds(array $voteIds): array
    {
        return [];
    }

    public function getEligiblePanelistUserIds(array $excludedUserIds, int $limit): array
    {
        return [];
    }

    /**
     * Fail-closed: when the trust engine is unavailable, treat every
     * user as suspended. Prevents suspended users from acting during
     * maintenance windows. Admins bypass via Permissions::is_not_suspended().
     */
    public function isSuspended(int $userId): bool
    {
        return true;
    }
}
