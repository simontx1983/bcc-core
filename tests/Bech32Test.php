<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Crypto\Bech32;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bech32 decoding is strict: one valid encoding decodes, nothing else.
 * Includes BIP-173's own valid and invalid test vectors.
 */
#[CoversClass(Bech32::class)]
final class Bech32Test extends TestCase
{
    public function testRoundTrip(): void
    {
        foreach ([str_repeat("\x00", 20), str_repeat("\xff", 20), random_bytes(20), random_bytes(32)] as $bytes) {
            $encoded = Bech32::encode('cosmosvaloper', $bytes);
            self::assertSame(['hrp' => 'cosmosvaloper', 'bytes' => $bytes], Bech32::decode($encoded));
            self::assertSame(['hrp' => 'cosmosvaloper', 'bytes' => $bytes], Bech32::decode(strtoupper($encoded)), 'all-uppercase is valid');
        }
    }

    public function testRealAddressesDecodeToTwentyBytes(): void
    {
        $json = file_get_contents(__DIR__ . '/fixtures/cosmos-real-txs.json');
        self::assertIsString($json);
        $vectors = json_decode($json, true);
        self::assertIsArray($vectors);

        foreach ($vectors as $v) {
            $decoded = Bech32::decode((string) $v['from']);
            self::assertNotNull($decoded, (string) $v['from']);
            self::assertSame(20, strlen($decoded['bytes']));
            self::assertSame($v['from'], Bech32::encode($decoded['hrp'], $decoded['bytes']), 'round trip');
        }
    }

    /** BIP-173 valid strings (bech32, not bech32m). */
    public function testBip173ValidVectors(): void
    {
        foreach (['A12UEL5L', 'a12uel5l', 'abcdef1qpzry9x8gf2tvdw0s3jn54khce6mua7lmqqqxw', 'split1checkupstagehandshakeupstreamerranterredcaperred2y9e3w'] as $s) {
            self::assertNotNull(Bech32::decode($s), $s);
        }
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        $valid = Bech32::encode('cosmos', str_repeat("\x01", 20));
        return [
            'mixed case'             => ['A12UeL5L'],
            'bad checksum'           => [substr($valid, 0, -1) . ($valid[strlen($valid) - 1] === 'q' ? 'p' : 'q')],
            'character outside set'  => [substr($valid, 0, 10) . 'b' . substr($valid, 11)],
            'empty hrp'              => ['1pzry9x0s0muk'],
            'no separator'           => ['pzry9x0s0muk'],
            'checksum too short'     => ['li1dgmt3'],
            'control character'      => ["\x7f" . '1axkwrx'],
            'too long'               => [Bech32::encode('cosmos', str_repeat("\x01", 80))],
            'empty string'           => [''],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidStringsDoNotDecode(string $s): void
    {
        self::assertNull(Bech32::decode($s));
    }

    /** 5 bits of data cannot make a byte: excess padding is refused, not rounded. */
    public function testExcessPaddingIsRefused(): void
    {
        // hrp "a", one data character, valid checksum: 5 bits, no whole byte.
        $one = self::withChecksum('a', [0]);
        self::assertNull(Bech32::decode($one));
    }

    /** 20 bits make two bytes and 4 padding bits, which must be zero. */
    public function testNonZeroPaddingIsRefused(): void
    {
        self::assertSame(['hrp' => 'a', 'bytes' => "\x00\x00"], Bech32::decode(self::withChecksum('a', [0, 0, 0, 0])));
        self::assertNull(Bech32::decode(self::withChecksum('a', [0, 0, 0, 1])));
    }

    /** @param list<int> $data 5-bit values */
    private static function withChecksum(string $hrp, array $data): string
    {
        $ref = new \ReflectionMethod(Bech32::class, 'polymod');
        $exp = new \ReflectionMethod(Bech32::class, 'hrpExpand');
        $ref->setAccessible(true);
        $exp->setAccessible(true);
        /** @var list<int> $expanded */
        $expanded = $exp->invoke(null, $hrp);
        $polymod  = ((int) $ref->invoke(null, array_merge($expanded, $data, [0, 0, 0, 0, 0, 0]))) ^ 1;
        $charset  = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $out      = $hrp . '1';
        foreach ($data as $d) {
            $out .= $charset[$d];
        }
        for ($i = 0; $i < 6; $i++) {
            $out .= $charset[($polymod >> (5 * (5 - $i))) & 31];
        }
        return $out;
    }
}
