<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\TrendingDataInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * Returns empty so the trending surface renders blank rather than
 * crashing. Sustained activation = "what's trending" feature shows
 * nothing to anyone.
 */
final class NullTrendingData implements TrendingDataInterface
{
    public function getTrendingPages(int $limit): array
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_trending_data', 'activation');
        return [];
    }
}
