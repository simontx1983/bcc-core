<?php

declare(strict_types=1);

namespace BCC\Core\Crypto\Tests;

use BCC\Core\Crypto\SolanaSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * SolanaSignatureVerifier::verify() answers false when the signature
 * library throws, whatever it throws.
 *
 * Native ext-sodium throws only SodiumException. Where it is absent,
 * WordPress's sodium_compat polyfill provides
 * sodium_crypto_sign_verify_detached(), and it can also throw TypeError,
 * RangeException or InvalidArgumentException. Those must be a failed
 * verification like any other, not an exception escaping to the caller.
 *
 * The library is replaced by a namespaced stub (see the stub file), so
 * these cases need no real key: the fixtures below are fixed base58
 * strings that decode to exactly 64 and 32 bytes, so verify() reaches the
 * library call. testTheStubIsWhatAnswers proves it does.
 */
#[CoversClass(SolanaSignatureVerifier::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SolanaVerifierLibraryErrorTest extends TestCase
{
    /** 64 bytes of 0x07, base58. */
    private const SIGNATURE = '99eUso3aSbE9tqGSTXzo3TLfKb9RkMTURrHKQ1K7Zh3BbeqPevr5E1iCbpTjqHuTFLtfxTTD5ekfVuZFzQyEQf8';

    /** 32 bytes of 0x05, base58. */
    private const ADDRESS = 'LbUiWL3xVV8hTFYBVdbTNrpDo41NKS6o3LHHuDzjfcY';

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('SolanaSignatureVerifier base58 requires ext-gmp.');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            self::markTestSkipped('SolanaSignatureVerifier requires sodium (ext or polyfill).');
        }
        require_once __DIR__ . '/Stubs/solana-verify-throwing-stub.php';
        $GLOBALS['__bcc_sodium_calls'] = 0;
        unset($GLOBALS['__bcc_sodium_throw'], $GLOBALS['__bcc_sodium_answer']);
    }

    /** ANTI-VACUITY: the fixtures reach the library, and its answer is verify()'s. */
    public function testTheStubIsWhatAnswers(): void
    {
        $GLOBALS['__bcc_sodium_answer'] = true;
        self::assertTrue(SolanaSignatureVerifier::verify('nonce', self::SIGNATURE, self::ADDRESS));

        $GLOBALS['__bcc_sodium_answer'] = false;
        self::assertFalse(SolanaSignatureVerifier::verify('nonce', self::SIGNATURE, self::ADDRESS));

        self::assertSame(2, $GLOBALS['__bcc_sodium_calls']);
    }

    /** @return array<string, array{class-string<\Throwable>}> */
    public static function libraryErrors(): array
    {
        return [
            'SodiumException (native)'                 => [\SodiumException::class],
            'TypeError (sodium_compat)'                => [\TypeError::class],
            'RangeException (sodium_compat)'           => [\RangeException::class],
            'InvalidArgumentException (sodium_compat)' => [\InvalidArgumentException::class],
            'any other Error'                          => [\Error::class],
        ];
    }

    #[DataProvider('libraryErrors')]
    public function testALibraryErrorIsAFailedVerification(string $throwable): void
    {
        $GLOBALS['__bcc_sodium_throw'] = $throwable;

        $result = SolanaSignatureVerifier::verify('nonce', self::SIGNATURE, self::ADDRESS);

        self::assertFalse($result);
        self::assertSame(1, $GLOBALS['__bcc_sodium_calls'], 'the library was not reached, so this proves nothing');

        // Logged once, naming what was thrown.
        self::assertCount(1, \BCC\Core\Log\Logger::$errors);
        self::assertSame($throwable, \BCC\Core\Log\Logger::$errors[0][1]['exception'] ?? null);
    }
}
