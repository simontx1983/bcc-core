<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\TrustReadServiceInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fail-safe implementation returned when bcc-trust is not active.
 *
 * Data queries return empty results (no false data).
 * Access gates return RESTRICTIVE defaults (fail-closed) so suspended
 * users cannot regain access during trust engine maintenance or plugin
 * deactivation. Admins are exempt via the Permissions class bypass.
 * getEligiblePanelistUserIds() returns [] to prevent unqualified
 * panelist selection.
 *
 * Observability: every method records to DegradationMetrics. The
 * fail-closed methods (`isSuspended`, `lockActiveVoteForDispute`)
 * use specific event names because their security posture is
 * operationally distinct from the fail-open empty-read methods —
 * fail-closed denies access platform-wide; fail-open just degrades
 * data availability. Operators triage them differently.
 */
final class NullTrustReadService implements TrustReadServiceInterface
{
    public function getVoteById(int $voteId): ?array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_trust_read', 'activation');
        return null;
    }

    public function getActiveVotesForPage(int $pageId, int $limit = 50, int $offset = 0): array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_trust_read', 'activation');
        return [];
    }

    public function countActiveVotesForPage(int $pageId): int
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_trust_read', 'activation');
        return 0;
    }

    public function getVotesByIds(array $voteIds): array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_trust_read', 'activation');
        return [];
    }

    public function getEligiblePanelistUserIds(array $excludedUserIds, int $limit): array
    {
        // Fail-open empty: dispute scheduler proceeds with no panelists.
        // The reconcile sweep will retry once bcc-trust is reachable.
        \BCC\Core\Observability\DegradationMetrics::record('null_trust_read', 'eligible_panelists');
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
        // Observability counter — records every fail-closed deny so
        // operators can detect "we've been blocking everyone as suspended
        // for N minutes" before non-admin users start filing tickets.
        \BCC\Core\Observability\DegradationMetrics::record('null_trust_read', 'is_suspended');
        return true;
    }

    /**
     * Fail-closed: when the trust engine is unavailable we cannot prove the
     * vote is still active, so dispute creation against it must abort.
     */
    public function lockActiveVoteForDispute(int $voteId): bool
    {
        // Fail-closed: dispute creation aborts. Specific event because
        // sustained activation = users trying to dispute votes can't
        // open disputes — different operator response than the
        // generic activation signal.
        \BCC\Core\Observability\DegradationMetrics::record('null_trust_read', 'lock_active_vote_for_dispute');
        return false;
    }
}
