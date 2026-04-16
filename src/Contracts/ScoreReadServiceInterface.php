<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only contract for retrieving trust scores across plugin boundaries.
 *
 * Consumer plugins (e.g. bcc-search) call this interface instead of
 * querying trust_page_scores directly. The trust engine provides the
 * implementation and owns all caching / invalidation.
 */
interface ScoreReadServiceInterface
{
    /**
     * Return trust scores for a batch of page IDs.
     *
     * Returns an associative array keyed by page_id. Pages without a score
     * row are omitted from the result (consumers should treat missing keys
     * as "no score").
     *
     * Each entry contains at minimum:
     * - total_score  (float)
     * - reputation_tier (string)
     *
     * @param int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string}>
     */
    public function getScoresForPageIds(array $pageIds): array;

    /**
     * Return enriched score data for a batch of page IDs.
     *
     * Like getScoresForPageIds() but includes additional fields from the
     * read model needed for unified ranking across search and discovery.
     * The ranking_score uses the same composite formula as /bcc/v1/discover
     * so consumers get consistent trust-based ordering.
     *
     * Each entry contains:
     * - total_score       (float)  Raw trust score (0–100).
     * - reputation_tier   (string) elite|trusted|neutral|caution|risky.
     * - ranking_score     (float)  Composite score matching /discover ranking.
     * - endorsement_count (int)    Total endorsements.
     * - is_verified       (bool)   Page owner verification status.
     * - follower_count    (int)    PeepSo follower count.
     *
     * @param int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, follower_count: int}>
     */
    public function getEnrichedScoresForPageIds(array $pageIds): array;
}
