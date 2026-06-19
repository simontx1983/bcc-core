<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Crypto\CosmosSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Cosmos bech32 address-derivation tests.
 *
 * Exercises the private static deriveCosmosAddress() + convertBits() on
 * CosmosSignatureVerifier via reflection (the public verify() path wraps
 * these). Pure crypto/hashing — no WordPress, no DB, no gmp.
 *
 * Known vector (Cosmos SDK secp256k1 test pubkey):
 *   02a1633cafcc01ebfb6d78e39f687a1f0995c62fc95f51ead10a02ee0be551b5dc
 * Converted from a standalone CLI verification harness to a PHPUnit
 * TestCase so it runs in the suite + CI.
 */
#[CoversClass(CosmosSignatureVerifier::class)]
final class CosmosVerifierTest extends TestCase
{
    /** Compressed secp256k1 pubkey from the Cosmos SDK test vectors. */
    private const PUBKEY_HEX = '02a1633cafcc01ebfb6d78e39f687a1f0995c62fc95f51ead10a02ee0be551b5dc';

    /**
     * @param array<int, mixed> $args
     */
    private static function callPrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod(CosmosSignatureVerifier::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    private static function pubKeyRaw(): string
    {
        $raw = hex2bin(self::PUBKEY_HEX);
        self::assertIsString($raw);
        return $raw;
    }

    private static function derive(string $pubKeyRaw, string $addressHint): string
    {
        $out = self::callPrivate('deriveCosmosAddress', [$pubKeyRaw, $addressHint]);
        self::assertIsString($out);
        return $out;
    }

    public function testKnownCosmosHubVectorFormat(): void
    {
        $derived = self::derive(self::pubKeyRaw(), 'cosmos1anything');
        self::assertStringStartsWith('cosmos1', $derived);
        self::assertSame(45, strlen($derived), 'cosmos1 (7) + 38 data chars');
    }

    public function testDeterminismSameKeySameAddress(): void
    {
        self::assertSame(
            self::derive(self::pubKeyRaw(), 'cosmos1anything'),
            self::derive(self::pubKeyRaw(), 'cosmos1anything')
        );
    }

    public function testDifferentHrpProducesDifferentAddress(): void
    {
        $cosmos = self::derive(self::pubKeyRaw(), 'cosmos1anything');
        $osmo   = self::derive(self::pubKeyRaw(), 'osmo1anything');
        self::assertStringStartsWith('osmo1', $osmo);
        self::assertNotSame($cosmos, $osmo, 'HRP participates in the bech32 checksum');
    }

    public function testCrossChainHrpConsistency(): void
    {
        self::assertStringStartsWith('akash1', self::derive(self::pubKeyRaw(), 'akash1anything'));
    }

    public function testMismatchDetection(): void
    {
        $derived  = self::derive(self::pubKeyRaw(), 'cosmos1anything');
        $otherRaw = hex2bin('03b8e3e96d1f3e8ae8b0e6cee6b459b04ee6cc16b80d831a3b6e3c8e6ef0b7d2b4');
        self::assertIsString($otherRaw);
        $other = self::derive($otherRaw, 'cosmos1anything');

        self::assertNotSame($derived, $other, 'different keys → different addresses');
        self::assertFalse(hash_equals($derived, $other), 'hash_equals rejects the mismatch');
    }

    public function testMalformedInputs(): void
    {
        // Short key: deriveCosmosAddress does not length-validate (verify() does)
        // — it still formats an address.
        $shortKey = hex2bin(str_repeat('ab', 32));
        self::assertIsString($shortKey);
        self::assertNotSame('', self::derive($shortKey, 'cosmos1x'));

        // No '1' separator → empty string.
        self::assertSame('', self::derive(self::pubKeyRaw(), 'noseparator'));
        // '1' at position 0 (empty HRP) → empty string.
        self::assertSame('', self::derive(self::pubKeyRaw(), '1abc'));
    }

    public function testRoundTripFromOwnAddress(): void
    {
        $derived = self::derive(self::pubKeyRaw(), 'cosmos1anything');
        self::assertSame($derived, self::derive(self::pubKeyRaw(), $derived));
    }

    public function testConvertBits8To5(): void
    {
        $converted = self::callPrivate('convertBits', [str_repeat("\xff", 20), 8, 5, true]);
        self::assertIsArray($converted);
        self::assertCount(32, $converted, '20 bytes (160 bits) → 32 five-bit groups');
        self::assertSame(31, $converted[0], 'all-ones input → 0b11111');
    }

    public function testBech32EncodingFormat(): void
    {
        $derived = self::derive(self::pubKeyRaw(), 'cosmos1anything');
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $data    = substr($derived, strlen('cosmos1'));

        self::assertSame(strlen($data), strspn($data, $charset), 'only valid bech32 chars');
        self::assertSame($derived, strtolower($derived), 'bech32 is lowercase only');
    }
}
