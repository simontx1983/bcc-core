<?php

namespace BCC\Core;

use BCC\Core\Contracts\DisputeAdjudicationInterface;
use BCC\Core\Contracts\ScoreContributorInterface;
use BCC\Core\Contracts\ScoreReadServiceInterface;
use BCC\Core\Contracts\TrustReadServiceInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceLocator
{
    public static function resolveDisputeAdjudication(): ?DisputeAdjudicationInterface
    {
        $service = apply_filters('bcc.resolve.dispute_adjudication', null);

        return $service instanceof DisputeAdjudicationInterface ? $service : null;
    }

    public static function resolveTrustReadService(): ?TrustReadServiceInterface
    {
        $service = apply_filters('bcc.resolve.trust_read_service', null);

        return $service instanceof TrustReadServiceInterface ? $service : null;
    }

    public static function resolveScoreContributor(): ?ScoreContributorInterface
    {
        $service = apply_filters('bcc.resolve.score_contributor', null);

        return $service instanceof ScoreContributorInterface ? $service : null;
    }

    public static function resolveScoreReadService(): ?ScoreReadServiceInterface
    {
        $service = apply_filters('bcc.resolve.score_read_service', null);

        return $service instanceof ScoreReadServiceInterface ? $service : null;
    }
}
