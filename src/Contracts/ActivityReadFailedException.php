<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by PeepSoActivityRepository's activity reads when the read did
 * NOT RUN (a database fault), as opposed to "no rows" (F-275).
 *
 * Before this, a failed feed read came back as `[]` / null, which every
 * caller then rendered as an empty feed or a 404: a database blip looked
 * like "nobody has posted" or "this post does not exist". bcc-trust turns
 * this into a retryable `503 bcc_unavailable` instead.
 *
 * ⚠ OPT-IN, so no existing caller changes. A read throws only when its
 * caller asks for it (the `$throwOnReadFailure` parameter, or an
 * ActivityFeedService built with that flag). Every other caller keeps the
 * historical empty answer.
 *
 * The message names the method only. The driver error is NOT copied in:
 * wpdb already logs it, and it can carry schema names.
 */
final class ActivityReadFailedException extends \RuntimeException
{
}
