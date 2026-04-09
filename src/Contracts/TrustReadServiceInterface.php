<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

interface TrustReadServiceInterface
{
    /**
     * Return a trust-owned vote snapshot for read-only workflows such as dispute creation.
     *
     * Expected keys:
     * - id
     * - page_id
     * - voter_user_id
     * - vote_type
     * - weight
     * - reason
     * - status
     * - created_at
     *
     * @return array<string, mixed>|null
     */
    public function getVoteById(int $voteId): ?array;

    /**
     * Return active vote snapshots for a page, newest first.
     *
     * @param int $limit  Maximum rows to return (default 50, max 500).
     * @param int $offset Starting offset for pagination.
     * @return array<int, array<string, mixed>>
     */
    public function getActiveVotesForPage(int $pageId, int $limit = 50, int $offset = 0): array;

    /**
     * Count active votes for a page.
     */
    public function countActiveVotesForPage(int $pageId): int;

    /**
     * Return vote snapshots for a batch of vote IDs, keyed by vote_id.
     *
     * Unlike getVoteById(), this does NOT filter on vote status — it returns
     * votes regardless of whether they are active, deleted, or disputed.
     * This is required for admin views that display historical dispute data.
     *
     * Each entry contains:
     * - vote_type   (int)
     * - weight      (float)
     * - reason      (string)
     * - created_at  (string|null)
     *
     * Missing vote IDs are omitted from the result.
     *
     * @param int[] $voteIds
     * @return array<int, array{vote_type: int, weight: float, reason: string, created_at: ?string}>
     */
    public function getVotesByIds(array $voteIds): array;

    /**
     * Return canonical wp_user_id values eligible to serve as dispute panelists.
     *
     * @param array<int, int> $excludedUserIds
     * @return array<int, int>
     */
    public function getEligiblePanelistUserIds(array $excludedUserIds, int $limit): array;
}
