<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\TrustReadServiceInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust-engine is not active.
 *
 * All methods return safe empty/null values so that consumer plugins
 * (bcc-disputes, bcc-search) never need to null-check the service.
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

    public function isSuspended(int $userId): bool
    {
        return false;
    }
}
