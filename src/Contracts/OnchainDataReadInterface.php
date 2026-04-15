<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only contract for retrieving on-chain data across plugin boundaries.
 *
 * Consumer plugins (e.g. peepso-integration) call this interface instead of
 * directly accessing bcc-onchain-signals repositories. The onchain-signals
 * plugin provides the implementation.
 */
interface OnchainDataReadInterface
{
    /**
     * Return paginated validator rows for a project (shadow CPT post ID).
     *
     * @param int    $projectId  Shadow CPT post_id (e.g. validator post).
     * @param int    $page       Page number (1-based).
     * @param int    $perPage    Items per page.
     * @param string $orderBy    Column to sort by (implementation validates).
     * @return array{items: array<int, object>, total: int, pages: int}
     */
    public function getValidatorsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_stake'): array;

    /**
     * Return paginated collection rows for a project.
     *
     * @param int    $projectId     Shadow CPT post_id (e.g. nft post).
     * @param int    $page          Page number (1-based).
     * @param int    $perPage       Items per page.
     * @param string $orderBy       Column to sort by.
     * @param bool   $includeHidden If true, includes hidden collections (for owner view).
     * @return array{items: array<int, object>, total: int, pages: int}
     */
    public function getCollectionsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_volume', bool $includeHidden = false): array;

    /**
     * Return pre-computed aggregate stats for a project's validators.
     *
     * @param int $projectId Shadow CPT post_id.
     * @return array{active_count: int, chains_count: int, total_stake: float, total_delegators: int, top_validator: ?object}
     */
    public function getValidatorAggregateStats(int $projectId): array;

    /**
     * Return all collections for a project (visible only, for aggregation).
     *
     * @param int $projectId Shadow CPT post_id.
     * @return array{items: array<int, object>, total: int, pages: int}
     */
    public function getAllCollectionsForProject(int $projectId): array;

    /**
     * Enrich collection items with badge flags (creator/holder).
     *
     * @param array<int, object> $items    Collection items from getCollectionsForProject().
     * @param int   $ownerId  Page owner user ID.
     * @param int   $viewerId Current viewer user ID (0 = logged out).
     * @return array<int, object> Same items with is_creator and viewer_holds flags.
     */
    public function enrichCollectionsWithBadges(array $items, int $ownerId, int $viewerId = 0): array;

    /**
     * Merge on-chain collections with manual ACF repeater rows.
     *
     * @param array<int, object> $onchainItems Items from getCollectionsForProject().
     * @param array<int, array<string, mixed>> $manualRows   ACF repeater rows.
     * @return array<int, object> Merged, deduplicated list.
     */
    public function mergeCollectionsWithManual(array $onchainItems, array $manualRows): array;
}
