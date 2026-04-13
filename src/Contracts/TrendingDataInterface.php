<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only contract for retrieving trending page data across plugin boundaries.
 *
 * Consumer plugins (e.g. bcc-search) call this interface instead of directly
 * accessing bcc-trust-engine's TableRegistry and read model tables.
 */
interface TrendingDataInterface
{
    /**
     * Get trending page IDs with their trust scores.
     *
     * @param int $limit Max results to return.
     * @return object[] Rows with ->ID, ->total_score, ->reputation_tier.
     */
    public function getTrendingPages(int $limit): array;
}
