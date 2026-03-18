<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

interface DisputeAdjudicationInterface
{
    public function acceptVoteDispute(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $voterId,
        int $resolvedBy
    ): bool;

    public function rejectVoteDispute(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $resolvedBy
    ): bool;
}
