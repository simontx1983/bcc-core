<?php
/**
 * Stubs for SolanaVerifierLibraryErrorTest ONLY.
 *
 * - `BCC\Core\Crypto\sodium_crypto_sign_verify_detached()`. The verifier
 *   calls the function UNQUALIFIED from that namespace, so PHP resolves this
 *   declaration before the global one, and the test controls what the
 *   signature library does. It throws the Throwable named in
 *   $GLOBALS['__bcc_sodium_throw'] (a class name), or returns the bool in
 *   $GLOBALS['__bcc_sodium_answer'].
 * - A recording `BCC\Core\Log\Logger` double: the real one writes under
 *   WP_CONTENT_DIR, which a unit process doesn't have.
 *
 * ⚠⚠ SUBPROCESS-ONLY, required from setUp(), never at file level: once
 * declared, this function answers for EVERY verify() in the process, so a
 * shared process would break the real-signature tests.
 */

declare(strict_types=1);

namespace BCC\Core\Crypto {
    if (!\function_exists('BCC\Core\Crypto\sodium_crypto_sign_verify_detached')) {
        function sodium_crypto_sign_verify_detached(string $signature, string $message, string $publicKey): bool
        {
            unset($signature, $message, $publicKey);
            $GLOBALS['__bcc_sodium_calls'] = ($GLOBALS['__bcc_sodium_calls'] ?? 0) + 1;

            $throw = $GLOBALS['__bcc_sodium_throw'] ?? null;
            if (is_string($throw)) {
                throw new $throw('stub: signature library failure');
            }
            return (bool) ($GLOBALS['__bcc_sodium_answer'] ?? false);
        }
    }
}

namespace BCC\Core\Log {
    // ⚠ `class_exists($x, false)`: a bare class_exists() would autoload the
    // real Logger, and this double would never be declared.
    if (!\class_exists('BCC\Core\Log\Logger', false)) {
        final class Logger
        {
            /** @var list<array{string, array<string, mixed>}> */
            public static array $errors = [];

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                self::$errors[] = [$message, $context];
            }
        }
    }
}
