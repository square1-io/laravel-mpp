<?php

namespace Square1\Mpp\Support\Evm;

use InvalidArgumentException;

/**
 * Minimal RLP (Recursive Length Prefix) decoder for raw EVM transaction bytes.
 *
 * We only ever DECODE: the Tempo settlement path re-broadcasts the exact signed
 * bytes the client presented, so it never needs to re-encode. Decoding lets us
 * read the transaction's `calls[]` (and thus the token transfer recipient,
 * amount and memo) to validate them against the challenge before broadcasting.
 *
 * A decoded value is either a string (a byte string, returned as a 0x-prefixed
 * hex string) or a nested list (array) of such values — mirroring viem/ox's
 * `Rlp.toHex` shape that the mppx reference implementation consumes.
 */
final class Rlp
{
    /**
     * Decode RLP bytes (given as a 0x-hex string or raw binary) into a nested
     * structure of 0x-hex strings and arrays.
     *
     * @return string|array<int, mixed>
     */
    public static function decode(string $input): string|array
    {
        $bytes = self::toBinary($input);
        $offset = 0;
        $result = self::decodeItem($bytes, $offset);

        if ($offset !== strlen($bytes)) {
            throw new InvalidArgumentException('Trailing bytes after RLP item.');
        }

        return $result;
    }

    /**
     * @return string|array<int, mixed>
     */
    private static function decodeItem(string $bytes, int &$offset): string|array
    {
        if ($offset >= strlen($bytes)) {
            throw new InvalidArgumentException('Unexpected end of RLP input.');
        }

        $prefix = ord($bytes[$offset]);

        // Single byte [0x00, 0x7f]: it is its own encoding.
        if ($prefix <= 0x7F) {
            $offset++;

            return '0x'.bin2hex($bytes[$offset - 1]);
        }

        // Short string [0x80, 0xb7]: 0-55 bytes.
        if ($prefix <= 0xB7) {
            $len = $prefix - 0x80;
            $offset++;
            $str = self::take($bytes, $offset, $len);

            return '0x'.bin2hex($str);
        }

        // Long string [0xb8, 0xbf]: length-of-length follows.
        if ($prefix <= 0xBF) {
            $lenOfLen = $prefix - 0xB7;
            $offset++;
            $len = self::readLength($bytes, $offset, $lenOfLen);
            $str = self::take($bytes, $offset, $len);

            return '0x'.bin2hex($str);
        }

        // Short list [0xc0, 0xf7]: 0-55 bytes of payload.
        if ($prefix <= 0xF7) {
            $len = $prefix - 0xC0;
            $offset++;

            return self::decodeList($bytes, $offset, $len);
        }

        // Long list [0xf8, 0xff].
        $lenOfLen = $prefix - 0xF7;
        $offset++;
        $len = self::readLength($bytes, $offset, $lenOfLen);

        return self::decodeList($bytes, $offset, $len);
    }

    /**
     * @return array<int, mixed>
     */
    private static function decodeList(string $bytes, int &$offset, int $payloadLength): array
    {
        $end = $offset + $payloadLength;

        if ($end > strlen($bytes)) {
            throw new InvalidArgumentException('RLP list length exceeds input.');
        }

        $items = [];
        while ($offset < $end) {
            $items[] = self::decodeItem($bytes, $offset);
        }

        if ($offset !== $end) {
            throw new InvalidArgumentException('RLP list items overran the declared length.');
        }

        return $items;
    }

    private static function readLength(string $bytes, int &$offset, int $lenOfLen): int
    {
        $raw = self::take($bytes, $offset, $lenOfLen);
        $len = 0;
        for ($i = 0; $i < strlen($raw); $i++) {
            $len = ($len << 8) | ord($raw[$i]);
        }

        return $len;
    }

    private static function take(string $bytes, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($bytes)) {
            throw new InvalidArgumentException('RLP segment length exceeds input.');
        }

        $segment = substr($bytes, $offset, $length);
        $offset += $length;

        return $segment;
    }

    private static function toBinary(string $input): string
    {
        if (str_starts_with($input, '0x') || str_starts_with($input, '0X')) {
            $hex = substr($input, 2);

            if ($hex === '') {
                return '';
            }

            if (strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
                throw new InvalidArgumentException('Invalid hex RLP input.');
            }

            return hex2bin($hex);
        }

        return $input;
    }
}
