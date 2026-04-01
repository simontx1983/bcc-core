<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\DisputeAdjudicationInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust-engine is not active.
 */
final class NullDisputeAdjudication implements DisputeAdjudicationInterface
{
    public function acceptVoteDispute(int $disputeId, int $voteId, int $pageId, int $voterId, int $resolvedBy): bool
    {
        return false;
    }

    public function rejectVoteDispute(int $disputeId, int $voteId, int $pageId, int $resolvedBy): bool
    {
        return false;
    }
}
