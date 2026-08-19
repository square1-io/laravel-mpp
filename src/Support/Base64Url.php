<?php

namespace Square1\Mpp\Support;

/**
 * Base64url without padding, per RFC 4648 Section 5 — the encoding MPP uses for
 * the challenge `request`/`opaque` parameters, the credential, and the receipt.
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
