<?php

namespace BCC\Core\NullServices;

use BCC\Core\Contracts\RecalcQueueReadInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fail-safe RecalcQueueRead for when bcc-trust is not active.
 *
 * Returns null — the NullObject IS unreachable by definition, and
 * null lets the health endpoint surface "queue status unknown"
 * instead of falsely reporting "0 = nothing queued". The `source`
 * field in the health payload still says 'unavailable' for the same
 * reason, but dashboards that key only on pending_pages now see a
 * truthful signal.
 */
final class NullRecalcQueueRead implements RecalcQueueReadInterface
{
    public function pendingCount(): ?int
    {
        return null;
    }
}
