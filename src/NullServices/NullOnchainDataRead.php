<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\OnchainDataReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 */
final class NullOnchainDataRead implements OnchainDataReadInterface
{
    /** @return array{items: array<int, object>, total: int, pages: int} */
    public function getValidatorsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_stake'): array
    {
        return ['items' => [], 'total' => 0, 'pages' => 0];
    }

    /** @return array{items: array<int, object>, total: int, pages: int} */
    public function getCollectionsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_volume', bool $includeHidden = false): array
    {
        return ['items' => [], 'total' => 0, 'pages' => 0];
    }

    public function getValidatorAggregateStats(int $projectId): array
    {
        return [
            'active_count'     => 0,
            'chains_count'     => 0,
            'total_stake'      => 0.0,
            'total_delegators' => 0,
            'top_validator'    => null,
        ];
    }

    /** @return array{items: array<int, object>, total: int, pages: int} */
    public function getAllCollectionsForProject(int $projectId): array
    {
        return ['items' => [], 'total' => 0, 'pages' => 0];
    }

    /**
     * @param array<int, object> $items
     * @return array<int, object>
     */
    public function enrichCollectionsWithBadges(array $items, int $ownerId, int $viewerId = 0): array
    {
        return $items;
    }

    /**
     * @param array<int, object> $onchainItems
     * @param array<int, array<string, mixed>> $manualRows
     * @return array<int, object>
     */
    public function mergeCollectionsWithManual(array $onchainItems, array $manualRows): array
    {
        return $onchainItems;
    }
}
