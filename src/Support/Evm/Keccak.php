<?php

namespace Square1\Mpp\Support\Evm;

/**
 * Pure-PHP Keccak-256 (the Ethereum hash) — NOT FIPS SHA3-256.
 *
 * Ethereum/EVM uses original Keccak with the `0x01` domain-separation pad,
 * whereas PHP's `hash('sha3-256', …)` uses the standardised `0x06` pad and
 * therefore produces different digests. The Tempo/mppx wire format hashes with
 * Keccak-256 in three places this package must reproduce byte-for-byte:
 *
 *   - ABI function selectors (`keccak256(signature)[0..4]`),
 *   - the on-chain transaction hash (`keccak256(serializedTransaction)`),
 *   - the MPP attribution memo fingerprints (server fp, challenge nonce).
 *
 * Implemented over GMP so it works wherever the package runs without a native
 * keccak build. It is only used on small inputs (a few hundred bytes at most),
 * so the cost is negligible.
 */
final class Keccak
{
    /** Round constants (iota step). */
    private const RC = [
        '0x0000000000000001', '0x0000000000008082', '0x800000000000808a', '0x8000000080008000',
        '0x000000000000808b', '0x0000000080000001', '0x8000000080008081', '0x8000000000008009',
        '0x000000000000008a', '0x0000000000000088', '0x0000000080008009', '0x000000008000000a',
        '0x000000008000808b', '0x800000000000008b', '0x8000000000008089', '0x8000000000008003',
        '0x8000000000008002', '0x8000000000000080', '0x000000000000800a', '0x800000008000000a',
        '0x8000000080008081', '0x8000000000008080', '0x0000000080000001', '0x8000000080008008',
    ];

    /** Rotation offsets (rho step), indexed [x][y]. */
    private const ROT = [
        [0, 36, 3, 41, 18],
        [1, 44, 10, 45, 2],
        [62, 6, 43, 15, 61],
        [28, 55, 25, 21, 56],
        [27, 20, 39, 8, 14],
    ];

    /**
     * Keccak-256 of raw bytes, returned as 32 raw bytes.
     */
    public static function hash(string $message): string
    {
        $rate = 136; // 1088-bit rate for Keccak-256 (capacity 512).

        // Keccak padding: append 0x01, zero-fill, set the top bit of the last byte.
        $message .= "\x01";
        while (strlen($message) % $rate !== 0) {
            $message .= "\x00";
        }
        $message[strlen($message) - 1] = $message[strlen($message) - 1] | "\x80";

        $state = [];
        for ($i = 0; $i < 25; $i++) {
            $state[$i] = gmp_init(0);
        }
        $mask = gmp_sub(gmp_pow(2, 64), 1);

        $blocks = intdiv(strlen($message), $rate);
        for ($b = 0; $b < $blocks; $b++) {
            for ($i = 0; $i < intdiv($rate, 8); $i++) {
                $lane = gmp_init(0);
                for ($j = 0; $j < 8; $j++) {
                    $byte = ord($message[$b * $rate + $i * 8 + $j]);
                    $lane = gmp_or($lane, gmp_mul($byte, gmp_pow(2, 8 * $j)));
                }
                $state[$i] = gmp_xor($state[$i], $lane);
            }
            self::permute($state, $mask);
        }

        // Squeeze the first 32 bytes (4 lanes), little-endian per lane.
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $lane = $state[$i];
            for ($j = 0; $j < 8; $j++) {
                $byte = gmp_intval(gmp_and(gmp_div_q($lane, gmp_pow(2, 8 * $j)), 255));
                $out .= chr($byte);
            }
        }

        return $out;
    }

    /**
     * Keccak-256 returned as a lowercase 0x-prefixed hex string.
     */
    public static function hashHex(string $message): string
    {
        return '0x'.bin2hex(self::hash($message));
    }

    private static function rotl(\GMP $x, int $n, \GMP $mask): \GMP
    {
        if ($n === 0) {
            return $x;
        }

        $left = gmp_and(gmp_mul($x, gmp_pow(2, $n)), $mask);
        $right = gmp_div_q($x, gmp_pow(2, 64 - $n));

        return gmp_or($left, $right);
    }

    /**
     * @param  array<int, \GMP>  $state
     */
    private static function permute(array &$state, \GMP $mask): void
    {
        for ($round = 0; $round < 24; $round++) {
            // theta
            $c = [];
            for ($x = 0; $x < 5; $x++) {
                $c[$x] = gmp_xor(gmp_xor(gmp_xor(gmp_xor($state[$x], $state[$x + 5]), $state[$x + 10]), $state[$x + 15]), $state[$x + 20]);
            }
            $d = [];
            for ($x = 0; $x < 5; $x++) {
                $d[$x] = gmp_xor($c[($x + 4) % 5], self::rotl($c[($x + 1) % 5], 1, $mask));
            }
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $state[$x + 5 * $y] = gmp_xor($state[$x + 5 * $y], $d[$x]);
                }
            }

            // rho + pi
            $b = [];
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $b[$y + 5 * (((2 * $x) + (3 * $y)) % 5)] = self::rotl($state[$x + 5 * $y], self::ROT[$x][$y], $mask);
                }
            }

            // chi
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $state[$x + 5 * $y] = gmp_xor(
                        $b[$x + 5 * $y],
                        gmp_and(gmp_and(gmp_com($b[(($x + 1) % 5) + 5 * $y]), $mask), $b[(($x + 2) % 5) + 5 * $y])
                    );
                }
            }

            // iota
            $state[0] = gmp_xor($state[0], gmp_init(self::RC[$round], 16));
        }
    }
}
