<?php

namespace Square1\Mpp\Support;

use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Resolves the HMAC key that the package uses to sign payment challenges.
 *
 * The order of preference is:
 *   1. An explicit MPP_CHALLENGE_SECRET. This is the recommendation for
 *      production, because a site owner can rotate it separately from APP_KEY. A
 *      rotation then invalidates the 402 responses that are in flight, and never
 *      a session that the server has issued.
 *   2. Otherwise a key that the class DERIVES from APP_KEY, with an HMAC and a
 *      domain-separation label. The package therefore works with no config, and
 *      does not reuse the raw bytes of APP_KEY for a second cryptographic
 *      purpose.
 *
 * The class throws only when neither value is available. An application with no
 * APP_KEY does not work in any case. The signer therefore never uses an empty or
 * guessable key.
 */
class ChallengeSecret
{
    /**
     * The domain-separation label.
     *
     * Increase the suffix if the derivation scheme has to change. That rotates
     * every derived key, as a change to any secret does.
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
