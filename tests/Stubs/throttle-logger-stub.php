<?php
/**
 * F-289: a recording double for bcc-core's Logger, for the Throttle tests
 * whose fail-closed denies log an error. The real Logger writes log files
 * and needs WordPress; this records the message in
 * $GLOBALS['__thr']['logged']. Load it before the first deny.
 */

declare(strict_types=1);

namespace BCC\Core\Log;

if (!class_exists(__NAMESPACE__ . '\Logger', false)) {
    final class Logger
    {
        /** @param array<string, mixed> $context */
        public static function error(string $message, array $context = []): void
        {
            $GLOBALS['__thr']['logged'][] = $message;
        }
    }
}
