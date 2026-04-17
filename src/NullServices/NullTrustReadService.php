<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\TrustReadServiceInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fail-safe implementation returned when bcc-trust-engine is not active.
 *
 * Data queries return empty results (no false data).
 * Access gates return PERMISSIVE defaults (fail-open) so users are not
 * locked out during trust engine maintenance or plugin deactivation.
 * The only exception is getEligiblePanelistUserIds() which returns []
 * to prevent unqualified panelist selection.
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
     * Fail-open: when the trust engine is unavailable, assume users are
     * NOT suspended. Returning true here would lock out every non-admin
     * user from all authenticated actions (voting, endorsing, reporting)
     * whenever the trust engine plugin is deactivated or crashes.
     */
    public function isSuspended(int $userId): bool
    {
        return false;
    }
}
