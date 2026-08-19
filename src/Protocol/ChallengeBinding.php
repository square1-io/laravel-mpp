<?php

namespace Square1\Mpp\Protocol;

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Support\Base64Url;

/**
 * The MPP core spec's recommended stateless challenge binding: the challenge id
 * IS an HMAC-SHA256 over seven fixed positional slots —
 *
 *   realm | method | intent | request_b64 | expires | digest | opaque_b64
 *
 * — base64url-encoded without padding. Absent optional slots contribute an
 * empty string, so (expires set, no digest) and (no expires, digest set)
 * produce distinct inputs and a future eighth slot cannot silently change the
 * HMAC of challenges that omit it.
 *
 * A client cannot alter price, recipient, expiry, body digest or correlation
 * data between the 402 and the paid retry without invalidating the id;
 * verification is a recompute-and-compare, no signature parameter on the wire.
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
