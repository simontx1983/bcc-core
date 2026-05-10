<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\DisputeAdjudicationInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * No-op implementation returned when bcc-trust is not active.
 *
 * Both methods fail-closed (return false) so the dispute panel cannot
 * silently approve or reject votes when the trust engine is unreachable.
 * The DisputeScheduler reconcile sweep retries automatically once the
 * real adjudicator is bound.
 */
final class NullDisputeAdjudication implements DisputeAdjudicationInterface
{
    public function acceptVoteDispute(int $disputeId, int $voteId, int $pageId, int $voterId, int $resolvedBy): bool
    {
        \BCC\Core\Observability\DegradationMetrics::record('null_dispute_adjudication', 'activation');
        return false;
    }

    public function rejectVoteDispute(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $reporterId,
        int $resolvedBy,
        bool $quorumMet
    ): bool {
        \BCC\Core\Observability\DegradationMetrics::record('null_dispute_adjudication', 'activation');
        return false;
    }
}
