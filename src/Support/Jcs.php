<?php

namespace Square1\Mpp\Support;

use InvalidArgumentException;

/**
 * JSON Canonicalization Scheme (RFC 8785) encoder.
 *
 * The MPP core spec mandates JCS for the challenge `request` and `opaque`
 * values before base64url encoding: the challenge-binding HMAC covers the
 * encoded blob as it appears on the wire, so any serialisation-order or
 * escaping difference between implementations breaks verification.
 *
 * Scope: object keys sorted by UTF-16 code unit, no insignificant whitespace,
 * minimal string escaping (unescaped unicode and slashes). Floats are
 * rejected — every monetary value in MPP is a string of minor units, and an
 * accidental float is a bug we want loud, not canonicalised.
 */
final class Jcs
{
    public static function encode(mixed $value): string
    {
        // A stdClass is always a JSON object, even when empty or when its keys
        // look list-like. This keeps {} distinct from [] at any nesting depth,
        // which a PHP array cannot express (both decode to []).
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
     * Encode an associative map as a canonical JSON object: keys sorted by
     * UTF-16 code unit, no insignificant whitespace.
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
     * RFC 8785 §3.2.3: property names sort by their UTF-16 code units.
     */
    private static function compareUtf16(string $a, string $b): int
    {
        return strcmp(
            (string) mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            (string) mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        );
    }
}
