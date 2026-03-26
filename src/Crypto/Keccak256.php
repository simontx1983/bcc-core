<?php
/**
 * Keccak-256 Hash Implementation
 *
 * Pure PHP implementation of the original Keccak-256 (NOT NIST SHA3-256).
 * Ethereum uses Keccak-256, which differs from SHA3-256 in the padding byte.
 *
 * Requires: GMP extension.
 *
 * @package BCC\Core\Crypto
 */

namespace BCC\Core\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

class Keccak256 {

    // Round constants (64-bit, stored as hex strings for GMP)
    private const RC = [
        '0000000000000001', '0000000000008082', '800000000000808a',
        '8000000080008000', '000000000000808b', '0000000080000001',
        '8000000080008081', '8000000000008009', '000000000000008a',
        '0000000000000088', '0000000080008009', '000000008000000a',
        '000000008000808b', '800000000000008b', '8000000000008089',
        '8000000000008003', '8000000000008002', '8000000000000080',
        '000000000000800a', '800000008000000a', '8000000080008081',
        '8000000000008080', '0000000080000001', '8000000080008008',
    ];

    // Rotation offsets for ρ step [x][y]
    private const ROT = [
        [0, 36,  3, 41, 18],
        [1, 44, 10, 45,  2],
        [62, 6, 43, 15, 61],
        [28,55, 25, 21, 56],
        [27,20, 39,  8, 14],
    ];

    private static \GMP $mask64;

    private static function init(): void {
        if (!isset(self::$mask64)) {
            self::$mask64 = gmp_init('ffffffffffffffff', 16);
        }
    }

    private static function rotl64(\GMP $x, int $n): \GMP {
        $mask = self::$mask64;
        $left  = gmp_and(gmp_mul($x, gmp_pow(gmp_init(2), $n)), $mask);
        $right = gmp_div_q($x, gmp_pow(gmp_init(2), 64 - $n));
        return gmp_and(gmp_or($left, $right), $mask);
    }

    private static function keccakF(array &$A): void {
        self::init();
        $mask = self::$mask64;

        for ($round = 0; $round < 24; $round++) {
            $C = [];
            for ($x = 0; $x < 5; $x++) {
                $C[$x] = gmp_xor(gmp_xor(gmp_xor(gmp_xor($A[$x][0], $A[$x][1]), $A[$x][2]), $A[$x][3]), $A[$x][4]);
            }
            $D = [];
            for ($x = 0; $x < 5; $x++) {
                $D[$x] = gmp_xor($C[($x + 4) % 5], self::rotl64($C[($x + 1) % 5], 1));
            }
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $A[$x][$y] = gmp_xor($A[$x][$y], $D[$x]);
                }
            }

            $B = array_fill(0, 5, array_fill(0, 5, gmp_init(0)));
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $B[$y][(2 * $x + 3 * $y) % 5] = self::rotl64($A[$x][$y], self::ROT[$x][$y]);
                }
            }

            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $A[$x][$y] = gmp_and(
                        gmp_xor($B[$x][$y], gmp_and(gmp_xor($B[($x + 1) % 5][$y], $mask), $B[($x + 2) % 5][$y])),
                        $mask
                    );
                }
            }

            $A[0][0] = gmp_and(gmp_xor($A[0][0], gmp_init(self::RC[$round], 16)), $mask);
        }
    }

    /**
     * Compute Keccak-256 of binary input.
     *
     * @param string $input  Raw binary string
     * @param bool   $binary Return raw binary (true) or hex string (false)
     * @return string
     */
    public static function hash(string $input, bool $binary = false): string {

        self::init();
        $mask = self::$mask64;

        $rate     = 136;
        $outputLen = 32;

        $inputLen = strlen($input);
        $padLen   = $rate - ($inputLen % $rate);
        $input   .= "\x01" . str_repeat("\x00", $padLen - 1);
        $input[$inputLen + $padLen - 1] = chr(ord($input[$inputLen + $padLen - 1]) | 0x80);

        $A = array_fill(0, 5, array_fill(0, 5, gmp_init(0)));

        for ($block = 0; $block < strlen($input); $block += $rate) {
            for ($i = 0; $i < $rate / 8; $i++) {
                $word = gmp_init(0);
                for ($b = 7; $b >= 0; $b--) {
                    $word = gmp_add(gmp_mul($word, gmp_init(256)), gmp_init(ord($input[$block + $i * 8 + $b])));
                }
                $word = gmp_and($word, $mask);
                $x = $i % 5;
                $y = intdiv($i, 5);
                $A[$x][$y] = gmp_and(gmp_xor($A[$x][$y], $word), $mask);
            }
            self::keccakF($A);
        }

        $output = '';
        $needed = $outputLen;
        $laneIdx = 0;
        while ($needed > 0) {
            $x    = $laneIdx % 5;
            $y    = intdiv($laneIdx, 5);
            $lane = $A[$x][$y];
            /** @phpstan-ignore smaller.alwaysTrue */
            for ($b = 0; $b < 8 && $needed > 0; $b++) {
                $output .= chr(gmp_intval(gmp_and($lane, gmp_init(0xff))));
                $lane    = gmp_div_q($lane, gmp_init(256));
                $needed--;
            }
            $laneIdx++;
        }

        return $binary ? $output : bin2hex($output);
    }
}
