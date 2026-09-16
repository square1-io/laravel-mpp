<?php

namespace Square1\Mpp\Support;

/**
 * Base64url without padding, as RFC 4648 Section 5 defines it.
 *
 * MPP uses this encoding for the `request` and `opaque` parameters of a
 * challenge, for the credential, and for the receipt.
 */
final class Base64Url
{
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return string|null null when the input is not valid base64url
     */
    public static function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/[^A-Za-z0-9_-]/', $encoded)) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }
}
