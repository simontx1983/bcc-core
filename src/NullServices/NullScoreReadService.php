<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\ScoreReadServiceInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * Returns empty arrays so callers see "no scores known" rather than a
 * crash; the read-side degrades to empty data rather than failing closed.
 */
final class NullScoreReadService implements ScoreReadServiceInterface
{
    public function getScoresForPageIds(array $pageIds): array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_score_read', 'activation');
        return [];
    }

    public function getEnrichedScoresForPageIds(array $pageIds): array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_score_read', 'activation');
        return [];
    }
}
