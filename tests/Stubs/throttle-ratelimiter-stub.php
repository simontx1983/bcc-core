<?php
/**
 * F-290: a recording double for bcc-trust's RateLimiter, the backend
 * Throttle prefers. $GLOBALS['__thr']['rl'] records every call;
 * $GLOBALS['__thr']['rl_allow'] is what allowByKey() answers.
 *
 * $GLOBALS['__thr']['rl_has_release'] = false loads a variant WITHOUT
 * releaseByKey() — an older bcc-trust.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

if (!class_exists(__NAMESPACE__ . '\\RateLimiter', false)) {
    if (($GLOBALS['__thr']['rl_has_release'] ?? true) === true) {
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
        }
    } else {
        final class RateLimiter
        {
            /** An older bcc-trust: three parameters, no releaseByKey(). */
            public static function allowByKey(string $key, int $limit, int $window): bool
            {
                $GLOBALS['__thr']['rl'][] = ['allow', $key, $limit, $window, func_num_args() > 3 ? func_get_arg(3) : 'n/a'];
                return (bool) ($GLOBALS['__thr']['rl_allow'] ?? true);
            }
        }
    }
}
