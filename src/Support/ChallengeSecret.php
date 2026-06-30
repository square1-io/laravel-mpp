<?php

namespace Square1\Mpp\Support;

use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Resolves the HMAC key used to sign payment challenges.
 *
 * Preference order:
 *   1. An explicit MPP_CHALLENGE_SECRET — recommended for production, because it
 *      can be rotated independently of APP_KEY (rotating it only invalidates
 *      in-flight 402s, never issued sessions).
 *   2. Otherwise a key DERIVED from APP_KEY via HMAC with a domain-separation
 *      label — so the package works zero-config without reusing APP_KEY's raw
 *      bytes for a second cryptographic purpose.
 *
 * Throws only when neither is available (an app with no APP_KEY is already
 * broken), so the signer is never silently keyed with an empty/guessable value.
 */
class ChallengeSecret
{
    /**
     * Domain-separation label. Bump the suffix if the derivation scheme ever
     * needs to change (it rotates every derived key, like any secret change).
     */
    private const DERIVATION_LABEL = 'laravel-mpp:challenge-v1';

    public static function resolve(?string $configured, ?string $appKey): string
    {
        $configured = trim((string) $configured);

        if ($configured !== '') {
            return $configured;
        }

        $appKey = trim((string) $appKey);

        if ($appKey !== '') {
            return hash_hmac('sha256', self::DERIVATION_LABEL, $appKey);
        }

        throw new InvalidConfigurationException(
            'No challenge signing key is available. Set MPP_CHALLENGE_SECRET (recommended, '
            .'so it can be rotated independently) or ensure APP_KEY is set.'
        );
    }
}
