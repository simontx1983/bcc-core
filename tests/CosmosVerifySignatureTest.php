<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Crypto\CosmosSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * End-to-end tests for CosmosSignatureVerifier::verify() — the first
 * coverage of the actual verify path (CosmosVerifierTest covers only
 * address derivation).
 *
 * A real secp256k1 keypair is generated with OpenSSL per test run; the
 * ADR-036 sign-doc is obtained from the verifier's own private builder
 * via reflection, signed with openssl_sign, and the DER signature is
 * converted to the compact r||s form Keplr emits — normalized to low-S,
 * since OpenSSL emits high-S about half the time while Keplr always
 * normalizes.
 *
 * The headline security property mirrors EthSignatureVerifierTest: the
 * malleated twin (r, n - s) verifies for the same message under raw
 * OpenSSL, so the verifier's low-S check is the only thing rejecting
 * it. That twin must be rejected.
 *
 * Requires ext-openssl with secp256k1 support and ext-gmp; skipped
 * where either is unavailable.
 */
#[CoversClass(CosmosSignatureVerifier::class)]
final class CosmosVerifySignatureTest extends TestCase
{
    private const MESSAGE = 'BCC Cosmos verify test vector #1';
    private const N_HEX   = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    /** @var \OpenSSLAsymmetricKey */
    private $key;
    private string $pubCompressed = '';
    private string $address       = '';

    protected function setUp(): void
    {
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('Cosmos low-S check requires ext-gmp.');
        }
        if (!function_exists('openssl_pkey_new')) {
            self::markTestSkipped('Requires ext-openssl.');
        }
        $key = self::makeKey();
        if ($key === false) {
            self::markTestSkipped('OpenSSL build lacks secp256k1 keygen (or no openssl.cnf found).');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            self::markTestSkipped('OpenSSL did not expose EC point coordinates.');
        }

        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $prefix = (ord($y[31]) % 2 === 0) ? "\x02" : "\x03";

        $this->key           = $key;
        $this->pubCompressed = $prefix . $x;
        $this->address       = self::derive($this->pubCompressed);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * secp256k1 keygen. On Linux/CI the bare call works; on Windows PHP
     * builds openssl_pkey_new needs an explicit openssl.cnf, so retry
     * with the usual candidates before giving up.
     *
     * @return \OpenSSLAsymmetricKey|false
     */
    private static function makeKey()
    {
        $args = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'secp256k1',
        ];
        $key = @openssl_pkey_new($args);
        if ($key !== false) {
            return $key;
        }
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
        ]);
        foreach ($candidates as $cnf) {
            if (!is_file($cnf)) {
                continue;
            }
            $key = @openssl_pkey_new(['config' => $cnf] + $args);
            if ($key !== false) {
                return $key;
            }
        }
        return false;
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function callPrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod(CosmosSignatureVerifier::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    private static function derive(string $pubKeyRaw): string
    {
        $out = self::callPrivate('deriveCosmosAddress', [$pubKeyRaw, 'cosmos1hint']);
        self::assertIsString($out);
        return $out;
    }

    /** Parse a DER ECDSA signature into left-padded 32-byte r and s. */
    private static function derToRs(string $der): string
    {
        $pos = 2; // 0x30, seq len (always short-form for 64-byte-order sigs)
        self::assertSame("\x02", $der[$pos]);
        $rLen = ord($der[$pos + 1]);
        $r    = substr($der, $pos + 2, $rLen);
        $pos += 2 + $rLen;
        self::assertSame("\x02", $der[$pos]);
        $sLen = ord($der[$pos + 1]);
        $s    = substr($der, $pos + 2, $sLen);

        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    /** Keplr-style low-S normalization: if s > n/2, s := n - s. */
    private static function normalizeLowS(string $rs): string
    {
        $n = gmp_init(self::N_HEX, 16);
        $s = gmp_init(bin2hex(substr($rs, 32)), 16);
        if (gmp_cmp($s, gmp_div_q($n, 2)) > 0) {
            $s = gmp_sub($n, $s);
        }
        $sBin = str_pad(gmp_export($s), 32, "\x00", STR_PAD_LEFT);
        return substr($rs, 0, 32) . $sBin;
    }

    /** Flip a low-S signature to its high-S malleated twin. */
    private static function malleate(string $rs): string
    {
        $n = gmp_init(self::N_HEX, 16);
        $s = gmp_sub($n, gmp_init(bin2hex(substr($rs, 32)), 16));
        $sBin = str_pad(gmp_export($s), 32, "\x00", STR_PAD_LEFT);
        return substr($rs, 0, 32) . $sBin;
    }

    /** Sign the verifier's own ADR-036 doc for $message; low-S compact sig. */
    private function signCompact(string $message): string
    {
        $doc = self::callPrivate('buildAdr036SignDoc', [$message, $this->address]);
        self::assertIsString($doc);
        $der = '';
        self::assertTrue(openssl_sign($doc, $der, $this->key, OPENSSL_ALGO_SHA256));
        return self::normalizeLowS(self::derToRs($der));
    }

    // ── Happy path (real anchor for the whole verify pipeline) ──────────

    public function testValidSignatureVerifies(): void
    {
        self::assertTrue(CosmosSignatureVerifier::verify(
            self::MESSAGE,
            base64_encode($this->signCompact(self::MESSAGE)),
            $this->address,
            base64_encode($this->pubCompressed)
        ));
    }

    // ── Low-S malleability (the headline) ───────────────────────────────

    public function testHighSMalleableTwinRejected(): void
    {
        $twin = self::malleate($this->signCompact(self::MESSAGE));
        self::assertFalse(CosmosSignatureVerifier::verify(
            self::MESSAGE,
            base64_encode($twin),
            $this->address,
            base64_encode($this->pubCompressed)
        ));
    }

    // ── Integrity / malformed inputs ────────────────────────────────────

    public function testTamperedMessageRejected(): void
    {
        self::assertFalse(CosmosSignatureVerifier::verify(
            self::MESSAGE . ' tampered',
            base64_encode($this->signCompact(self::MESSAGE)),
            $this->address,
            base64_encode($this->pubCompressed)
        ));
    }

    public function testAddressMismatchRejected(): void
    {
        // Signature and pubkey are internally consistent, but the claimed
        // address belongs to nobody — derived-address check must fire.
        self::assertFalse(CosmosSignatureVerifier::verify(
            self::MESSAGE,
            base64_encode($this->signCompact(self::MESSAGE)),
            'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq',
            base64_encode($this->pubCompressed)
        ));
    }

    public function testZeroSRejected(): void
    {
        $rs = $this->signCompact(self::MESSAGE);
        $zeroed = substr($rs, 0, 32) . str_repeat("\x00", 32);
        self::assertFalse(CosmosSignatureVerifier::verify(
            self::MESSAGE,
            base64_encode($zeroed),
            $this->address,
            base64_encode($this->pubCompressed)
        ));
    }

    public function testGarbageSignatureRejected(): void
    {
        self::assertFalse(CosmosSignatureVerifier::verify(
            self::MESSAGE,
            base64_encode(random_bytes(64)),
            $this->address,
            base64_encode($this->pubCompressed)
        ));
    }
}
