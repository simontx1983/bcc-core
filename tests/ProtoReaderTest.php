<?php

declare(strict_types=1);

namespace BCC\Core\Tests;

use BCC\Core\Crypto\Cosmos\CosmosTxVerdict;
use BCC\Core\Crypto\Cosmos\ProtoDecodeException;
use BCC\Core\Crypto\Cosmos\ProtoReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ProtoReader reads only what the schema allows, and each message one way.
 */
#[CoversClass(ProtoReader::class)]
#[CoversClass(ProtoDecodeException::class)]
final class ProtoReaderTest extends TestCase
{
    /** @var array<int, array{0: int, 1: bool}> */
    private const SCHEMA = [1 => [ProtoReader::BYTES, false], 2 => [ProtoReader::VARINT, false], 3 => [ProtoReader::BYTES, true]];

    public function testReadsTheSchemasFields(): void
    {
        $m = ProtoReader::decode("\x0a\x02hi\x10\x96\x01\x1a\x01a\x1a\x00\x1a\x01b", self::SCHEMA);

        self::assertSame('hi', ProtoReader::bytes($m, 1));
        self::assertSame(150, ProtoReader::int($m, 2));
        self::assertSame(['a', '', 'b'], ProtoReader::all($m, 3), 'a repeated field keeps every value, in order');
        self::assertSame('', ProtoReader::bytes([], 1), 'absent is the empty default');
        self::assertSame(0, ProtoReader::int([], 2));
        self::assertSame([], ProtoReader::decode('', self::SCHEMA));
    }

    public function testTheLargestVarintIsReadAndOneMoreIsRefused(): void
    {
        $max = "\x10" . str_repeat("\xff", 8) . "\x7f"; // 2^63 - 1
        self::assertSame(PHP_INT_MAX, ProtoReader::int(ProtoReader::decode($max, self::SCHEMA), 2));

        $this->expectRefusal("\x10" . str_repeat("\x80", 9) . "\x01", CosmosTxVerdict::REASON_MALFORMED); // 2^63
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'an unknown field'               => ["\x22\x00", CosmosTxVerdict::REASON_UNSUPPORTED_FIELD],
            'field number 0'                 => ["\x02\x00", CosmosTxVerdict::REASON_MALFORMED],
            'the wrong wire type'            => ["\x08\x01", CosmosTxVerdict::REASON_MALFORMED],
            'a fixed64 field'                => ["\x09" . str_repeat("\x00", 8), CosmosTxVerdict::REASON_MALFORMED],
            'a singular field twice'         => ["\x0a\x00\x0a\x00", CosmosTxVerdict::REASON_MALFORMED],
            'a length past the end'          => ["\x0a\x05abc", CosmosTxVerdict::REASON_MALFORMED],
            'a truncated varint'             => ["\x10\x80", CosmosTxVerdict::REASON_MALFORMED],
            'a varint over 64 bits'          => ["\x10" . str_repeat("\xff", 9) . "\x02", CosmosTxVerdict::REASON_MALFORMED],
            'a varint longer than 10 bytes'  => ["\x10" . str_repeat("\x80", 10) . "\x00", CosmosTxVerdict::REASON_MALFORMED],
            'a truncated key'                => ["\x80", CosmosTxVerdict::REASON_MALFORMED],
        ];
    }

    #[DataProvider('refused')]
    public function testMalformedOrUnexpectedBytesAreRefused(string $bytes, string $reason): void
    {
        $this->expectRefusal($bytes, $reason);
    }

    private function expectRefusal(string $bytes, string $reason): void
    {
        try {
            ProtoReader::decode($bytes, self::SCHEMA);
            self::fail('decoded');
        } catch (ProtoDecodeException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
