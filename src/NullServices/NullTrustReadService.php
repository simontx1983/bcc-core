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
 * Access gates return RESTRICTIVE defaults (fail-closed) so suspended
 * users cannot regain access during trust engine maintenance or plugin
 * deactivation. Admins are exempt via the Permissions class bypass.
 * getEligiblePanelistUserIds() returns [] to prevent unqualified
 * panelist selection.
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
     * Fail-closed: when the trust engine is unavailable, assume users ARE
     * suspended. This prevents suspended users from regaining access during
     * trust engine maintenance or plugin deactivation.
     *
     * Admins are not affected because the Permissions class already applies
     * an admin bypass before calling isSuspended().
     */
    public function isSuspended(int $userId): bool
    {
        return true;
    }
}
