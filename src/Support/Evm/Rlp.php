<?php

namespace Square1\Mpp\Support\Evm;

use InvalidArgumentException;

/**
 * The smallest RLP (Recursive Length Prefix) decoder for raw EVM transaction
 * bytes.
 *
 * The package only DECODES. The Tempo settlement path broadcasts the exact
 * signed bytes that the client presented, so it never encodes anything.
 *
 * Decoding lets the package read the `calls[]` of the transaction, and therefore
 * the recipient, the amount and the memo of the token transfer. The package
 * validates those values against the challenge before it broadcasts.
 *
 * A decoded value is either a string or a nested list. A string is a byte
 * string, which the decoder returns as a hex string with a 0x prefix. A nested
 * list is an array of such values. This is the shape of `Rlp.toHex` in viem and
 * ox, which the mppx reference implementation reads.
 */
final class Rlp
{
    /**
     * Decodes RLP bytes into a nested structure of 0x-hex strings and arrays.
     *
     * The input is a 0x-hex string or raw binary.
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

        // A single byte in [0x00, 0x7f] is its own encoding.
        if ($prefix <= 0x7F) {
            $offset++;

            return '0x'.bin2hex($bytes[$offset - 1]);
        }

        // A short string, in [0x80, 0xb7], holds 0 to 55 bytes.
        if ($prefix <= 0xB7) {
            $len = $prefix - 0x80;
            $offset++;
            $str = self::take($bytes, $offset, $len);

            return '0x'.bin2hex($str);
        }

        // A long string, in [0xb8, 0xbf], carries the length of its length
        // first.
        if ($prefix <= 0xBF) {
            $lenOfLen = $prefix - 0xB7;
            $offset++;
            $len = self::readLength($bytes, $offset, $lenOfLen);
            $str = self::take($bytes, $offset, $len);

            return '0x'.bin2hex($str);
        }

        // A short list, in [0xc0, 0xf7], holds 0 to 55 bytes of payload.
        if ($prefix <= 0xF7) {
            $len = $prefix - 0xC0;
            $offset++;

            return self::decodeList($bytes, $offset, $len);
        }

        // A long list, in [0xf8, 0xff].
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
