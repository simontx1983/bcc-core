<?php
/**
 * A STRICT reader for the few protobuf messages a Cosmos transaction proof
 * needs (TxRaw, TxBody, AuthInfo, SignerInfo, ModeInfo, Fee, Coin, Any,
 * MsgSend, PubKey). Not a general protobuf library.
 *
 * Each message is read against a schema the caller states: field number →
 * wire type, and whether the field may repeat. Anything else is refused,
 * not skipped:
 *   - a field the schema does not name (reason `unsupported_field`), so a
 *     transaction that carries something the verifier does not understand
 *     is never accepted on what it does understand;
 *   - a named field with the wrong wire type, a singular field that appears
 *     twice (protobuf would keep the last or merge; refusing means there is
 *     only ever one reading of the bytes), field number 0, wire types other
 *     than varint and length-delimited, truncated input, a varint longer
 *     than 10 bytes or at or above 2^63 (reason `malformed`).
 *
 * Pure: no WordPress, no network.
 *
 * @package BCC\Core\Crypto\Cosmos
 */

namespace BCC\Core\Crypto\Cosmos;

if (!defined('ABSPATH')) {
    exit;
}

final class ProtoReader
{
    public const VARINT = 0;
    public const BYTES  = 2;

    /**
     * Decode one message.
     *
     * @param array<int, array{0: int, 1: bool}> $schema field number => [wire type, repeated]
     * @return array<int, list<int|string>> field number => its values, in order (absent = not set)
     * @throws ProtoDecodeException
     */
    public static function decode(string $bytes, array $schema): array
    {
        $out = [];
        $len = strlen($bytes);
        $i   = 0;
        while ($i < $len) {
            $key   = self::varint($bytes, $i, $len);
            $field = $key >> 3;
            $wire  = $key & 7;
            if ($field === 0) {
                throw new ProtoDecodeException(CosmosTxVerdict::REASON_MALFORMED, 'field number 0');
            }
            if (!isset($schema[$field])) {
                throw new ProtoDecodeException(CosmosTxVerdict::REASON_UNSUPPORTED_FIELD, "field {$field} is not allowed here");
            }
            [$wantWire, $repeated] = $schema[$field];
            if ($wire !== $wantWire) {
                throw new ProtoDecodeException(CosmosTxVerdict::REASON_MALFORMED, "field {$field} has wire type {$wire}");
            }
            if (!$repeated && isset($out[$field])) {
                throw new ProtoDecodeException(CosmosTxVerdict::REASON_MALFORMED, "field {$field} appears twice");
            }

            if ($wire === self::VARINT) {
                $out[$field][] = self::varint($bytes, $i, $len);
                continue;
            }
            $size = self::varint($bytes, $i, $len);
            if ($size > $len - $i) {
                throw new ProtoDecodeException(CosmosTxVerdict::REASON_MALFORMED, "field {$field} runs past the end");
            }
            $out[$field][] = (string) substr($bytes, $i, $size);
            $i += $size;
        }
        return $out;
    }

    /**
     * The single value of a singular bytes/string field, '' when absent.
     *
     * @param array<int, list<int|string>> $message
     */
    public static function bytes(array $message, int $field): string
    {
        $v = $message[$field][0] ?? '';
        return is_string($v) ? $v : '';
    }

    /**
     * The single value of a singular varint field, 0 when absent.
     *
     * @param array<int, list<int|string>> $message
     */
    public static function int(array $message, int $field): int
    {
        $v = $message[$field][0] ?? 0;
        return is_int($v) ? $v : 0;
    }

    /**
     * Every value of a repeated bytes field (empty list when absent).
     *
     * @param array<int, list<int|string>> $message
     * @return list<string>
     */
    public static function all(array $message, int $field): array
    {
        $out = [];
        foreach ($message[$field] ?? [] as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** @throws ProtoDecodeException */
    private static function varint(string $bytes, int &$i, int $len): int
    {
        $result = 0;
        for ($n = 0; $n < 10; $n++) {
            if ($i >= $len) {
                throw new ProtoDecodeException(CosmosTxVerdict::REASON_MALFORMED, 'truncated varint');
            }
            $byte = ord($bytes[$i++]);
            if ($n === 9 && $byte > 1) {
                throw new ProtoDecodeException(CosmosTxVerdict::REASON_MALFORMED, 'varint overflows 64 bits');
            }
            $result |= ($byte & 0x7f) << (7 * $n);
            if ($byte < 0x80) {
                if ($result < 0) {
                    // bit 63 set: an unsigned value PHP cannot hold, and no
                    // field read here can legitimately be that large
                    throw new ProtoDecodeException(CosmosTxVerdict::REASON_MALFORMED, 'varint at or above 2^63');
                }
                return $result;
            }
        }
        throw new ProtoDecodeException(CosmosTxVerdict::REASON_MALFORMED, 'varint longer than 10 bytes');
    }
}
