<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side contract for the trust-engine score-recalculation queue.
 *
 * bcc-core exposes an operational health view that needs to know how
 * many score rows are still pending recalculation. The implementation
 * lives in bcc-trust-engine; bcc-core must not reach into trust-engine
 * tables directly. Resolved via ServiceLocator; the NullObject returns
 * 0 so the health endpoint degrades gracefully when trust-engine is
 * unavailable.
 */
interface RecalcQueueReadInterface
{
    /**
     * Number of score rows flagged as needing recalculation.
     *
     * Implementations MUST be bounded (aggregate COUNT on an indexed
     * column) and MUST NOT throw — return 0 on failure so the health
     * endpoint does not cascade into a platform-wide outage.
     */
    public function pendingCount(): int;
}
