<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\RecalcQueueReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fail-safe RecalcQueueRead for when bcc-trust-engine is not active.
 *
 * Returns 0 pending — the health endpoint interprets this as
 * "no recalculation backlog" which is indistinguishable from
 * "trust engine unavailable". The `services.ScoreReadService` /
 * `trust_subsystem.trust_read_service_real` flags in the health
 * payload already surface the real reason.
 */
final class NullRecalcQueueRead implements RecalcQueueReadInterface
{
    public function pendingCount(): int
    {
        return 0;
    }
}
