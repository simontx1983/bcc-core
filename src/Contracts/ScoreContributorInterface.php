<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract for external plugins to contribute bonus scores to trust pages.
 *
 * Only bcc-trust-engine may write to trust_page_scores. External plugins
 * (e.g. bcc-onchain-signals) call this interface to apply bonuses, and the
 * trust engine handles the write, cache invalidation, and audit logging.
 */
interface ScoreContributorInterface
{
    /**
     * Apply a bonus score from an external source to a page's trust record.
     *
     * @param int    $pageId The page receiving the bonus.
     * @param string $source Identifier for the bonus origin (e.g. 'onchain').
     * @param float  $value  The bonus value to store (non-negative).
     *
     * @return bool True if the bonus was persisted, false on failure.
     */
    public function applyBonus(int $pageId, string $source, float $value): bool;
}
