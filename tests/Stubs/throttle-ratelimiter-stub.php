<?php
/**
 * F-290 / F-289: a recording double for bcc-trust's RateLimiter, the backend
 * Throttle prefers. $GLOBALS['__thr']['rl'] records every call;
 * $GLOBALS['__thr']['rl_allow'] is what allowByKey() answers, and
 * $GLOBALS['__thr']['rl_retry_after'] what retryAfterByKey() answers
 * (returned as given, so a test can hand back a non-int).
 *
 * Which bcc-trust it is:
 *   - default: the current one (allowByKey, releaseByKey, retryAfterByKey);
 *   - $GLOBALS['__thr']['rl_has_retry_after'] = false: an F-290 bcc-trust,
 *     with releaseByKey() but no retryAfterByKey();
 *   - $GLOBALS['__thr']['rl_has_release'] = false: an older bcc-trust, with
 *     a three-parameter allowByKey() and neither.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

if (!class_exists(__NAMESPACE__ . '\\RateLimiter', false)) {
    if (($GLOBALS['__thr']['rl_has_release'] ?? true) !== true) {
        final class RateLimiter
        {
            /** An older bcc-trust: three parameters, no releaseByKey(). */
            public static function allowByKey(string $key, int $limit, int $window): bool
            {
                $GLOBALS['__thr']['rl'][] = ['allow', $key, $limit, $window, func_num_args() > 3 ? func_get_arg(3) : 'n/a'];
                return (bool) ($GLOBALS['__thr']['rl_allow'] ?? true);
            }
        }
    } elseif (($GLOBALS['__thr']['rl_has_retry_after'] ?? true) !== true) {
        final class RateLimiter
        {
            /** An F-290 bcc-trust: no retryAfterByKey(). */
            public static function allowByKey(string $key, int $limit, int $window, bool $countDenied = true): bool
            {
                $GLOBALS['__thr']['rl'][] = ['allow', $key, $limit, $window, $countDenied];
                return (bool) ($GLOBALS['__thr']['rl_allow'] ?? true);
            }

            public static function releaseByKey(string $key, int $window): void
            {
                $GLOBALS['__thr']['rl'][] = ['release', $key, $window];
            }
        }
    } else {
        final class RateLimiter
        {
            public static function allowByKey(string $key, int $limit, int $window, bool $countDenied = true): bool
            {
                $GLOBALS['__thr']['rl'][] = ['allow', $key, $limit, $window, $countDenied];
                return (bool) ($GLOBALS['__thr']['rl_allow'] ?? true);
            }

            public static function releaseByKey(string $key, int $window): void
            {
                $GLOBALS['__thr']['rl'][] = ['release', $key, $window];
            }

            /** @return mixed */
            public static function retryAfterByKey(string $key, int $limit, int $window)
            {
                $GLOBALS['__thr']['rl'][] = ['retry_after', $key, $limit, $window];
                return $GLOBALS['__thr']['rl_retry_after'] ?? 42;
            }
        }
    }
}
