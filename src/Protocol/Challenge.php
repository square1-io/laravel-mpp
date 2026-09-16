<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;

/**
 * One MPP challenge, which is one `Payment …` entry in WWW-Authenticate, for one
 * settlement method. The spec is draft-ryan-httpauth-payment.
 *
 * A 402 that offers several rails carries several Challenges, and each one has
 * its own binding id.
 *
 * The wire parameters are the parameters of the spec: id, realm, method, intent,
 * request, expires, digest and opaque. The request is base64url JCS JSON, and
 * its shape depends on the rail.
 *
 * The economic extensions of the package are grants, for a metered bundle, and
 * scope. Those extensions and a random `nonce` per mint travel in `opaque`,
 * which is the server-correlation slot of the spec. The slot holds a flat string
 * map, encoded with JCS. The id binds it, and a conformant client echoes it
 * without a change. The nonce is what makes each minted id unique.
 */
class Challenge
{
    /**
     * @param  array<string, mixed>  $request  the payment request payload of the rail
     * @param  array<string, string>  $opaque  a flat string map of server correlation data
     * @param  string|null  $digest  the RFC 9530 Content-Digest of the challenged request body, or null when the request had no body
     */
    public function __construct(
        public readonly string $id,
        public readonly string $realm,
        public readonly string $method,
        public readonly string $intent,
        public readonly array $request,
        public readonly CarbonImmutable $expiresAt,
        public readonly array $opaque = [],
        public readonly ?string $digest = null,
    ) {}

    public function withId(string $id): self
    {
        return new self($id, $this->realm, $this->method, $this->intent, $this->request, $this->expiresAt, $this->opaque, $this->digest);
    }

    public function requestB64(): string
    {
        return Base64Url::encode(Jcs::encode($this->request));
    }

    public function opaqueB64(): string
    {
        return $this->opaque === [] ? '' : Base64Url::encode(Jcs::encode($this->opaque));
    }

    /**
     * Returns the RFC 3339 form, which the package uses both on the wire and in
     * the expires slot of the binding.
     */
    public function expiresParam(): string
    {
        return $this->expiresAt->toIso8601ZuluString();
    }

    /**
     * Returns the digest slot of the binding.
     *
     * An absent digest contributes the empty string. This parameter therefore
     * does not change an id that the server minted for a request with no body.
     */
    public function digestParam(): string
    {
        return $this->digest ?? '';
    }

    /**
     * Returns this challenge as one `Payment …` header entry.
     */
    public function headerValue(): string
    {
        $params = [
            'id' => $this->id,
            'realm' => $this->realm,
            'method' => $this->method,
            'intent' => $this->intent,
            'request' => $this->requestB64(),
            'expires' => $this->expiresParam(),
        ];

        if ($this->digest !== null) {
            $params['digest'] = $this->digest;
        }

        if ($this->opaque !== []) {
            $params['opaque'] = $this->opaqueB64();
        }

        $parts = [];
        foreach ($params as $name => $value) {
            $parts[] = sprintf('%s="%s"', $name, addcslashes($value, '"\\'));
        }

        return 'Payment '.implode(', ', $parts);
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThan($this->expiresAt);
    }

    /**
     * Returns the amount in minor units, as the request payload of the rail
     * carries it.
     */
    public function amount(): string
    {
        return (string) ($this->request['amount'] ?? '0');
    }

    public function currency(): string
    {
        return (string) ($this->request['currency'] ?? '');
    }

    public function grants(): int
    {
        return max(1, (int) ($this->opaque['grants'] ?? 1));
    }

    public function scope(): string
    {
        return (string) ($this->opaque['scope'] ?? '');
    }

    public function isMetered(): bool
    {
        return $this->grants() > 1;
    }
}
