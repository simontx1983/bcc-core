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

    /**
     * Reject a vote-dispute and, when quorum was met, apply the reporter
     * fraud + reputation penalty atomically inside bcc-trust's Core
     * domain transaction.
     *
     * Quorum semantics: pass $quorumMet = true ONLY when enough panelists
     * actually voted (see DisputeRepository::wasQuorumMetForDispute).
     * When false, the dispute status still flips to 'rejected' (the vote
     * stands) but NO reporter penalty is applied — reviewer silence is
     * not evidence of a bad-faith report.
     *
     * Prior architecture fired a `bcc.trust.dispute_rejected_penalty`
     * action from the disputes plugin (pre-M1). That hook is deprecated:
     * all score mutations must now flow through this contract so the
     * Core domain owns the transaction boundary and the Disputes domain
     * cannot apply scores behind its back.
     */
    public function rejectVoteDispute(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $reporterId,
        int $resolvedBy,
        bool $quorumMet
    ): bool;
}
