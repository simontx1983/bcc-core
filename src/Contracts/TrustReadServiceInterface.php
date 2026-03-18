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
     * @return array<int, array<string, mixed>>
     */
    public function getActiveVotesForPage(int $pageId): array;

    /**
     * Return canonical wp_user_id values eligible to serve as dispute panelists.
     *
     * @param array<int, int> $excludedUserIds
     * @return array<int, int>
     */
    public function getEligiblePanelistUserIds(array $excludedUserIds, int $limit): array;
}
