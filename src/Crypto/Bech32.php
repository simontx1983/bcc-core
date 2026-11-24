<?php
/**
 * Bech32 (BIP-173) encoding and STRICT decoding, for Cosmos addresses.
 *
 * Cosmos addresses use the original bech32 checksum (not bech32m). The
 * decoder refuses anything that is not exactly one valid encoding: mixed
 * case, characters outside the charset, a bad checksum, an empty HRP, an
 * overlong string, and non-zero or excess padding bits. A string that
 * decodes therefore names exactly one byte string.
 *
 * bcc-trust has its own `Onchain\Support\Bech32` (lenient on padding, no
 * length cap); moving it onto this one is a follow-up.
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class Bech32
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /**
     * Longest string decode() accepts. BIP-173's 90 is too short for
     * Cosmos's 32-byte addresses (module and ICA accounts); 20-byte account
     * and valoper addresses are well under it.
     */
    private const MAX_LENGTH = 128;

    /** Encode raw bytes under $hrp (lowercase). */
    public static function encode(string $hrp, string $bytes): string
    {
        $data = self::convertBits(array_values(unpack('C*', $bytes) ?: []), 8, 5, true) ?? [];

        $polymod  = self::polymod(array_merge(self::hrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0])) ^ 1;
        $checksum = [];
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }

        $encoded = $hrp . '1';
        foreach (array_merge($data, $checksum) as $d) {
            $encoded .= self::CHARSET[$d];
        }
        return $encoded;
    }

    /**
     * Decode a bech32 string to its HRP (lowercased) and raw bytes.
     * Null for anything that is not one valid bech32 encoding.
     *
     * @return array{hrp: string, bytes: string}|null
     */
    public static function decode(string $bech32): ?array
    {
        $len = strlen($bech32);
        if ($len < 8 || $len > self::MAX_LENGTH) {
            return null;
        }
        for ($i = 0; $i < $len; $i++) {
            $c = ord($bech32[$i]);
            if ($c < 33 || $c > 126) {
                return null;
            }
        }
        $lower = strtolower($bech32);
        if ($bech32 !== $lower && $bech32 !== strtoupper($bech32)) {
            return null; // mixed case
        }

        $sep = strrpos($lower, '1');
        if ($sep === false || $sep < 1 || $sep + 7 > $len) {
            return null; // empty HRP, or fewer than 6 checksum characters
        }
        $hrp = substr($lower, 0, $sep);

        $values = [];
        for ($i = $sep + 1; $i < $len; $i++) {
            $v = strpos(self::CHARSET, $lower[$i]);
            if ($v === false) {
                return null;
            }
            $values[] = $v;
        }
        if (self::polymod(array_merge(self::hrpExpand($hrp), $values)) !== 1) {
            return null;
        }

        $bytes = self::convertBits(array_slice($values, 0, -6), 5, 8, false);
        if ($bytes === null) {
            return null;
        }

        $raw = '';
        foreach ($bytes as $b) {
            $raw .= chr($b);
        }
        return ['hrp' => $hrp, 'bytes' => $raw];
    }

    /**
     * Regroup bits (8 → 5 to encode, 5 → 8 to decode). Without $pad the
     * leftover must be fewer than $fromBits bits and all zero, else null.
     *
     * @param list<int> $data
     * @return list<int>|null
     */
    private static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): ?array
    {
        $acc    = 0;
        $bits   = 0;
        $result = [];
        $maxV   = (1 << $toBits) - 1;

        foreach ($data as $value) {
            if ($value < 0 || ($value >> $fromBits) !== 0) {
                return null;
            }
            $acc   = (($acc << $fromBits) | $value) & 0xffffff;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits    -= $toBits;
                $result[] = ($acc >> $bits) & $maxV;
            }
        }

        if ($pad) {
            if ($bits > 0) {
                $result[] = ($acc << ($toBits - $bits)) & $maxV;
            }
        } elseif ($bits >= $fromBits || (($acc << ($toBits - $bits)) & $maxV) !== 0) {
            return null;
        }

        return $result;
    }

    /** @return list<int> */
    private static function hrpExpand(string $hrp): array
    {
        $expand = [];
        $len    = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $expand[] = ord($hrp[$i]) >> 5;
        }
        $expand[] = 0;
        for ($i = 0; $i < $len; $i++) {
            $expand[] = ord($hrp[$i]) & 31;
        }
        return $expand;
    }

    /** @param list<int> $values */
    private static function polymod(array $values): int
    {
        $generator = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk       = 1;
        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; $i++) {
                if ((($top >> $i) & 1) !== 0) {
                    $chk ^= $generator[$i];
                }
            }
        }
        return $chk;
    }
}
