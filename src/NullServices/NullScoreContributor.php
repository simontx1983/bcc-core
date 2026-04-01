<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\ScoreContributorInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust-engine is not active.
 *
 * Returns false so callers know the bonus was NOT persisted and can
 * queue a retry (e.g. bcc-onchain-signals bonus retry mechanism).
 */
final class NullScoreContributor implements ScoreContributorInterface
{
    public function applyBonus(int $pageId, string $source, float $value): bool
    {
        return false;
    }
}
