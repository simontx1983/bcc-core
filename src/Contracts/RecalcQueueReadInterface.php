<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-side contract for bcc-trust's score-recalculation queue.
 *
 * bcc-core exposes an operational health view that needs to know how
 * many score rows are still pending recalculation. The implementation
 * lives in bcc-trust; bcc-core must not reach into bcc-trust tables
 * directly. Resolved via ServiceLocator; the NullObject returns null
 * so the health endpoint can distinguish "queue empty" (0) from
 * "queue unreachable" (null) when bcc-trust is unavailable or its
 * underlying query fails.
 */
interface RecalcQueueReadInterface
{
    /**
     * Number of score rows flagged as needing recalculation, or null
     * when the queue cannot be queried (trust engine not wired, DB
     * error, etc.).
     *
     * Implementations MUST be bounded (aggregate COUNT on an indexed
     * column) and MUST NOT throw. Return null on any internal failure
     * so monitoring dashboards can alert on "we don't know" instead
     * of silently treating an unreachable backend as "nothing queued".
     */
    public function pendingCount(): ?int;
}
