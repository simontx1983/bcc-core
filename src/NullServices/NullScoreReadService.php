<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\ScoreReadServiceInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust-engine is not active.
 */
final class NullScoreReadService implements ScoreReadServiceInterface
{
    public function getScoresForPageIds(array $pageIds): array
    {
        return [];
    }
}
