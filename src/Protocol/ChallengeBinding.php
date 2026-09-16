<?php

namespace Square1\Mpp\Protocol;

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Support\Base64Url;

/**
 * The stateless challenge binding that the MPP core spec recommends.
 *
 * The challenge id IS an HMAC-SHA256 over seven fixed positional slots:
 *
 *   realm | method | intent | request_b64 | expires | digest | opaque_b64
 *
 * The package encodes the result as base64url without padding. An optional slot
 * that is absent contributes the empty string. The combination (expires set, no
 * digest) therefore produces a different input from (no expires, digest set). An
 * eighth slot that the package adds later also cannot change the HMAC of a
 * challenge that omits it.
 *
 * A client cannot change the price, the recipient, the expiry, the body digest
 * or the correlation data between the 402 and the paid retry. Any change makes
 * the id invalid. Verification recomputes the id and compares it, and the wire
 * format carries no signature parameter.
 */
final class ChallengeBinding
{
    public function __construct(private readonly string $secret)
    {
        if ($this->secret === '') {
            throw new InvalidConfigurationException(
                'mpp.secret is not set. Provide MPP_CHALLENGE_SECRET before minting challenges.'
            );
        }
    }

    public function id(
        string $realm,
        string $method,
        string $intent,
        string $requestB64,
        string $expires = '',
        string $digest = '',
        string $opaqueB64 = '',
    ): string {
        $input = implode('|', [$realm, $method, $intent, $requestB64, $expires, $digest, $opaqueB64]);

        return Base64Url::encode(hash_hmac('sha256', $input, $this->secret, binary: true));
    }

    public function verify(Challenge $challenge): bool
    {
        return hash_equals($this->idFor($challenge), $challenge->id);
    }

    public function idFor(Challenge $challenge): string
    {
        return $this->id(
            realm: $challenge->realm,
            method: $challenge->method,
            intent: $challenge->intent,
            requestB64: $challenge->requestB64(),
            expires: $challenge->expiresParam(),
            digest: $challenge->digestParam(),
            opaqueB64: $challenge->opaqueB64(),
        );
    }
}
