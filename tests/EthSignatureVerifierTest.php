<?php

declare(strict_types=1);

namespace BCC\Core\Crypto\Tests;

use BCC\Core\Crypto\EthSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Invariants for the secp256k1 ecrecover / EIP-191 personal_sign verifier.
 *
 * The valid vector below is for private key d = 1 (well-known address
 * 0x7e5f…395bdf), EIP-191-prefixed, naturally low-S, v = 0x1b. It was
 * derived and cross-checked against the verifier itself — so the happy-path
 * assertion is a real anchor, not a vacuous "everything returns false" pass.
 *
 * The headline security property is EIP-2 / BIP-62 malleability rejection:
 * for the valid (r, s) there is a twin (r, n−s, v^1) that recovers the SAME
 * address; accepting it would let an attacker forge a second distinct-on-disk
 * signature and defeat signature-bytes replay-dedup. The twin must be rejected.
 *
 * Requires ext-gmp (the verifier's secp256k1 math). Skipped where gmp is
 * absent (e.g. a bare CLI); CI runs it with gmp enabled.
 */
#[CoversClass(EthSignatureVerifier::class)]
final class EthSignatureVerifierTest extends TestCase
{
    private const MESSAGE = 'BCC EVM signature test vector #1';
    private const ADDRESS = '0x7e5f4552091a69125d5dfcb7b8c2659029395bdf';

    private const R = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private const S = '55ea547f62d7294b48632c3b4578d9a568f3da7ca84f30bed32f8445981d15a7';
    private const V = '1b';

    /** secp256k1 group order. */
    private const N = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    private const SIG = '0x' . self::R . self::S . self::V;

    protected function setUp(): void
    {
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('EthSignatureVerifier requires ext-gmp.');
        }
    }

    // ── Happy path (real anchor) ────────────────────────────────────────

    public function testValidSignatureRecoversAddress(): void
    {
        self::assertTrue(EthSignatureVerifier::verify(self::MESSAGE, self::SIG, self::ADDRESS));
    }

    public function testAddressMatchIsCaseInsensitive(): void
    {
        self::assertTrue(EthSignatureVerifier::verify(self::MESSAGE, self::SIG, strtoupper(self::ADDRESS)));
    }

    // ── EIP-2 low-S malleability (the headline) ─────────────────────────

    public function testHighSMalleableTwinRejected(): void
    {
        // (r, n−s, v^1) recovers the same address but is high-S → must be rejected.
        $highS = str_pad(
            gmp_strval(gmp_sub(gmp_init(self::N, 16), gmp_init(self::S, 16)), 16),
            64,
            '0',
            STR_PAD_LEFT
        );
        $twin = '0x' . self::R . $highS . '1c'; // v flipped 1b -> 1c
        self::assertFalse(EthSignatureVerifier::verify(self::MESSAGE, $twin, self::ADDRESS));
    }

    // ── Recovered-address / message integrity ───────────────────────────

    public function testWrongAddressRejected(): void
    {
        self::assertFalse(EthSignatureVerifier::verify(
            self::MESSAGE,
            self::SIG,
            '0x0000000000000000000000000000000000000002'
        ));
    }

    public function testTamperedMessageRejected(): void
    {
        self::assertFalse(EthSignatureVerifier::verify(self::MESSAGE . ' tampered', self::SIG, self::ADDRESS));
    }

    // ── Degenerate / malformed inputs ───────────────────────────────────

    public function testZeroRRejected(): void
    {
        $sig = '0x' . str_repeat('0', 64) . self::S . self::V;
        self::assertFalse(EthSignatureVerifier::verify(self::MESSAGE, $sig, self::ADDRESS));
    }

    public function testZeroSRejected(): void
    {
        $sig = '0x' . self::R . str_repeat('0', 64) . self::V;
        self::assertFalse(EthSignatureVerifier::verify(self::MESSAGE, $sig, self::ADDRESS));
    }

    public function testBadLengthRejected(): void
    {
        self::assertFalse(EthSignatureVerifier::verify(self::MESSAGE, '0xabcdef', self::ADDRESS));
    }

    public function testBadRecoveryIdRejected(): void
    {
        $sig = '0x' . self::R . self::S . '05'; // v=5 → not 0/1 after −27 normalisation
        self::assertFalse(EthSignatureVerifier::verify(self::MESSAGE, $sig, self::ADDRESS));
    }

    public function testNonHexSignatureRejected(): void
    {
        // 130 chars post-0x, passes the length gate, non-hex → must be a
        // clean false, not an uncaught ValueError from gmp_init (PHP 8).
        $sig = '0x' . str_repeat('zz', 65);
        self::assertFalse(EthSignatureVerifier::verify(self::MESSAGE, $sig, self::ADDRESS));
    }

    public function testSingleNonHexCharRejected(): void
    {
        // Valid r‖s, non-hex v byte — exercises the tail of the string.
        $sig = '0x' . self::R . self::S . 'g1';
        self::assertFalse(EthSignatureVerifier::verify(self::MESSAGE, $sig, self::ADDRESS));
    }

    public function testOversizedMessageRejected(): void
    {
        // > MAX_MESSAGE_LENGTH (1024) → early-rejected before any recovery.
        self::assertFalse(EthSignatureVerifier::verify(str_repeat('a', 1025), self::SIG, self::ADDRESS));
    }
}
