<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\ScoreContributorInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * Returns false so callers know the bonus was NOT persisted and can
 * queue a retry (e.g. bcc-trust's Onchain bonus retry mechanism).
 */
final class NullScoreContributor implements ScoreContributorInterface
{
    public function applyBonus(int $pageId, string $source, float $value): bool
    {
        // Sustained activation = score-bonus writes silently dropped (the
        // Onchain bonus retry queue picks them up once bcc-trust is back).
        \BCC\Core\Observability\DegradationMetrics::record('null_score_contributor', 'activation');
        return false;
    }
}
