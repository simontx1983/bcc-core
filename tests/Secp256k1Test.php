<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Crypto\Secp256k1;
use BCC\Core\Tests\Support\CosmosTxKit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/CosmosTxKit.php';

/**
 * Secp256k1::verifyDigest() is new pure-GMP ECDSA verification (the
 * Ethermint path signs Keccak-256 digests, which OpenSSL cannot verify from
 * PHP). It is held to OpenSSL's answer: over SHA-256 digests it must agree
 * with verifySha256() on valid, tampered and malleated signatures.
 * (recoverPublicKey() moved here from EthSignatureVerifier unchanged;
 * EthSignatureVerifierTest covers it.)
 */
#[CoversClass(Secp256k1::class)]
final class Secp256k1Test extends TestCase
{
    private CosmosTxKit $kit;

    protected function setUp(): void
    {
        if (!extension_loaded('gmp') || !function_exists('openssl_pkey_new')) {
            self::markTestSkipped('Requires ext-gmp and ext-openssl.');
        }
        $kit = CosmosTxKit::create();
        if ($kit === null) {
            self::markTestSkipped('OpenSSL build lacks secp256k1 keygen (or no openssl.cnf found).');
        }
        $this->kit = $kit;
    }

    public function testDigestVerificationAgreesWithOpenSsl(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $message = random_bytes(40 + $i);
            $sig     = $this->kit->sign($message);
            $digest  = hash('sha256', $message, true);

            self::assertTrue(Secp256k1::verifySha256($message, $sig, $this->kit->pubKey));
            self::assertTrue(Secp256k1::verifyDigest($digest, $sig, $this->kit->pubKey), "round {$i}");

            $tampered = hash('sha256', $message . 'x', true);
            self::assertFalse(Secp256k1::verifySha256($message . 'x', $sig, $this->kit->pubKey));
            self::assertFalse(Secp256k1::verifyDigest($tampered, $sig, $this->kit->pubKey));
        }
    }

    public function testAnotherKeyDoesNotVerify(): void
    {
        $other = CosmosTxKit::create();
        self::assertNotNull($other);
        $sig = $this->kit->sign('m');

        self::assertFalse(Secp256k1::verifyDigest(hash('sha256', 'm', true), $sig, $other->pubKey));
        self::assertFalse(Secp256k1::verifySha256('m', $sig, $other->pubKey));
    }

    /** The malleated twin (r, n - s) is a valid ECDSA signature; both paths refuse it. */
    public function testHighSIsRefused(): void
    {
        $sig  = $this->kit->sign('m');
        $twin = CosmosTxKit::malleate($sig);

        self::assertFalse(Secp256k1::verifySha256('m', $twin, $this->kit->pubKey));
        self::assertFalse(Secp256k1::verifyDigest(hash('sha256', 'm', true), $twin, $this->kit->pubKey));
    }

    public function testOutOfRangeOrMalformedInputIsRefused(): void
    {
        $sig    = $this->kit->sign('m');
        $digest = hash('sha256', 'm', true);
        $nBytes = (string) hex2bin(Secp256k1::N);

        self::assertFalse(Secp256k1::verifyDigest($digest, str_repeat("\x00", 32) . substr($sig, 32), $this->kit->pubKey), 'r = 0');
        self::assertFalse(Secp256k1::verifyDigest($digest, substr($sig, 0, 32) . str_repeat("\x00", 32), $this->kit->pubKey), 's = 0');
        self::assertFalse(Secp256k1::verifyDigest($digest, $nBytes . substr($sig, 32), $this->kit->pubKey), 'r = n');
        self::assertFalse(Secp256k1::verifyDigest(substr($digest, 1), $sig, $this->kit->pubKey), '31-byte digest');
        self::assertFalse(Secp256k1::verifyDigest($digest, substr($sig, 1), $this->kit->pubKey), '63-byte signature');
        self::assertFalse(Secp256k1::verifyDigest($digest, $sig, "\x04" . substr($this->kit->pubKey, 1)), 'bad key prefix');
    }

    public function testDecompressRecoversTheCurvePoint(): void
    {
        $xy = Secp256k1::decompress($this->kit->pubKey);
        self::assertIsString($xy);
        self::assertSame(substr($this->kit->pubKey, 1), substr($xy, 0, 32));
        self::assertSame(ord($this->kit->pubKey[0]) === 0x03, (ord($xy[63]) & 1) === 1, 'y parity follows the prefix');

        self::assertNull(Secp256k1::decompress("\x02" . str_repeat("\xff", 32)), 'x >= p');
        self::assertNull(Secp256k1::decompress(substr($this->kit->pubKey, 1)), '32 bytes');
    }
}
