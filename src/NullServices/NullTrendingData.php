<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\TrendingDataInterface;

if (!defined('ABSPATH')) {
    exit;
}

class NullTrendingData implements TrendingDataInterface
{
    public function getTrendingPages(int $limit): array
    {
        return [];
    }
}
