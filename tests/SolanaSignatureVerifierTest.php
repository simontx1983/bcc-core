<?php

declare(strict_types=1);

namespace BCC\Core\Crypto\Tests;

use BCC\Core\Crypto\SolanaSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Invariants for the Ed25519 / base58 Solana verifier.
 *
 * Vectors are generated at runtime with sodium keygen — Ed25519 keygen and
 * signing are fast and deterministic-enough that no fixed fixture is needed;
 * the happy-path assertion is a real anchor because a broken base58 decoder
 * or a broken verify call cannot produce a passing verification.
 *
 * The headline regression is the pre-decode length bound: base58Decode is an
 * O(n²) per-char GMP loop, so oversized user input must be rejected BEFORE
 * decoding (a 1 MB blob used to burn CPU before failing the 64-byte check).
 *
 * Requires ext-gmp (base58 decode) and ext-sodium (Ed25519). Skipped where
 * either is absent; CI runs with both enabled.
 */
#[CoversClass(SolanaSignatureVerifier::class)]
final class SolanaSignatureVerifierTest extends TestCase
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    protected function setUp(): void
    {
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('SolanaSignatureVerifier base58 requires ext-gmp.');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            self::markTestSkipped('SolanaSignatureVerifier requires ext-sodium.');
        }
    }

    /** Tiny test-local base58 encoder (Bitcoin/Solana alphabet). */
    private static function base58Encode(string $bytes): string
    {
        $num = gmp_init(bin2hex($bytes), 16);
        $out = '';
        while (gmp_cmp($num, 0) > 0) {
            [$num, $rem] = gmp_div_qr($num, 58);
            $out = self::ALPHABET[gmp_intval($rem)] . $out;
        }
        for ($i = 0; $i < strlen($bytes) && $bytes[$i] === "\x00"; $i++) {
            $out = '1' . $out;
        }
        return $out;
    }

    /** @return array{sig: string, pub: string} base58-encoded signature + pubkey for $message */
    private static function vector(string $message): array
    {
        $kp  = sodium_crypto_sign_keypair();
        $sig = sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($kp));
        return [
            'sig' => self::base58Encode($sig),
            'pub' => self::base58Encode(sodium_crypto_sign_publickey($kp)),
        ];
    }

    // ── Happy path (real anchor) ────────────────────────────────────────

    public function testValidSignatureVerifies(): void
    {
        $msg = 'BCC Solana test vector';
        $v   = self::vector($msg);
        self::assertTrue(SolanaSignatureVerifier::verify($msg, $v['sig'], $v['pub']));
    }

    // ── Message / key integrity ─────────────────────────────────────────

    public function testTamperedMessageRejected(): void
    {
        $msg = 'BCC Solana test vector';
        $v   = self::vector($msg);
        self::assertFalse(SolanaSignatureVerifier::verify($msg . ' tampered', $v['sig'], $v['pub']));
    }

    public function testWrongKeyRejected(): void
    {
        $msg   = 'BCC Solana test vector';
        $v     = self::vector($msg);
        $other = self::vector($msg);
        self::assertFalse(SolanaSignatureVerifier::verify($msg, $v['sig'], $other['pub']));
    }

    // ── Malformed inputs ────────────────────────────────────────────────

    public function testInvalidBase58CharRejected(): void
    {
        // '0', 'O', 'I', 'l' are all outside the base58 alphabet.
        $v = self::vector('x');
        self::assertFalse(SolanaSignatureVerifier::verify('x', '0OIl' . substr($v['sig'], 4), $v['pub']));
    }

    public function testEmptyInputsRejected(): void
    {
        $v = self::vector('x');
        self::assertFalse(SolanaSignatureVerifier::verify('x', '', $v['pub']));
        self::assertFalse(SolanaSignatureVerifier::verify('x', $v['sig'], ''));
    }

    public function testOversizedBase58BlobRejectedCheaply(): void
    {
        // 1 MB of valid-alphabet base58 — must be rejected by the length
        // bound BEFORE the O(n²) decode loop runs. Correctness assertion
        // only; timing assertions are flaky, the length gate is what
        // makes this cheap.
        $v    = self::vector('x');
        $blob = str_repeat('2', 1_000_000);
        self::assertFalse(SolanaSignatureVerifier::verify('x', $blob, $v['pub']));
        self::assertFalse(SolanaSignatureVerifier::verify('x', $v['sig'], $blob));
    }
}
