<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\OnchainDataReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-onchain-signals is not active.
 */
final class NullOnchainDataRead implements OnchainDataReadInterface
{
    public function getValidatorsForProject(int $projectId, int $page = 1, int $perPage = 8, string $orderBy = 'total_stake'): array
    {
        return ['items' => [], 'total' => 0, 'pages' => 0];
    }

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

    public function getAllCollectionsForProject(int $projectId): array
    {
        return ['items' => [], 'total' => 0, 'pages' => 0];
    }

    public function enrichCollectionsWithBadges(array $items, int $ownerId, int $viewerId = 0): array
    {
        return $items;
    }

    public function mergeCollectionsWithManual(array $onchainItems, array $manualRows): array
    {
        return $onchainItems;
    }
}
