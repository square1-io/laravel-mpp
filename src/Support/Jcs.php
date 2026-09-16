<?php

namespace Square1\Mpp\Support;

use InvalidArgumentException;

/**
 * An encoder for the JSON Canonicalization Scheme, RFC 8785.
 *
 * The MPP core spec requires JCS for the `request` and `opaque` values of a
 * challenge, before the package encodes them with base64url. The HMAC of the
 * challenge binding covers the encoded value as it appears on the wire. Any
 * difference in serialisation order or in escaping between two implementations
 * therefore breaks verification.
 *
 * The encoder sorts the keys of an object by UTF-16 code unit, emits no
 * insignificant whitespace, and escapes as little as possible. It leaves unicode
 * and slashes unescaped.
 *
 * The encoder rejects a float. Every monetary value in MPP is a string of minor
 * units. A float is therefore a defect, and the package reports it instead of
 * canonicalising it.
 */
final class Jcs
{
    public static function encode(mixed $value): string
    {
        // A stdClass is always a JSON object, even when it is empty, and even
        // when its keys look like list indexes. This keeps {} distinct from []
        // at any depth. A PHP array cannot express that difference, because both
        // decode to [].
        if ($value instanceof \stdClass) {
            return self::encodeObject(get_object_vars($value));
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::encode(...), $value)).']';
            }

            return self::encodeObject($value);
        }

        if (is_string($value)) {
            return self::encodeString($value);
        }

        if (is_int($value) || is_bool($value) || $value === null) {
            return json_encode($value);
        }

        throw new InvalidArgumentException(
            'JCS encoding of '.get_debug_type($value).' is not supported: '
            .'encode amounts and other numerics as strings.'
        );
    }

    /**
     * Encodes an associative map as a canonical JSON object.
     *
     * The method sorts the keys by UTF-16 code unit, and emits no insignificant
     * whitespace.
     *
     * @param  array<string, mixed>  $map
     */
    private static function encodeObject(array $map): string
    {
        $keys = array_keys($map);
        usort($keys, self::compareUtf16(...));

        $entries = [];
        foreach ($keys as $key) {
            $entries[] = self::encodeString((string) $key).':'.self::encode($map[$key]);
        }

        return '{'.implode(',', $entries).'}';
    }

    private static function encodeString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Sorts property names by their UTF-16 code units, as RFC 8785 §3.2.3
     * requires.
     */
    private static function compareUtf16(string $a, string $b): int
    {
        return strcmp(
            (string) mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            (string) mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        );
    }
}
