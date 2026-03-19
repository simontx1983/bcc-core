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
}
