<?php

namespace BCC\Core\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by TrustReadServiceInterface::isSuspended() when the suspension
 * state could not be READ (a database fault), as opposed to "not suspended".
 *
 * Permissions turns it into an UNKNOWN state that is never cached:
 * is_not_suspended() denies that one call, and suspensionState() returns
 * null so paths with persistent effects can decline to act.
 */
final class SuspensionUnknownException extends \RuntimeException
{
}
