<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;

/**
 * One MPP challenge: a single `Payment …` entry in WWW-Authenticate, for one
 * settlement method (spec: draft-ryan-httpauth-payment). A 402 that offers
 * several rails carries several Challenges, each with its own binding id.
 *
 * Wire parameters are exactly the spec's: id, realm, method, intent, request
 * (base64url JCS JSON, rail-specific shape), expires, digest, opaque. The package's
 * economic extensions — grants (metered bundles) and scope — plus a per-mint
 * random `nonce` ride in `opaque`, the spec's server-correlation slot: a flat
 * string map, JCS-encoded, bound into the id, echoed back verbatim by
 * conformant clients. The nonce is what makes each minted id unique.
 */
class Challenge
{
    /**
     * @param  array<string, mixed>  $request  rail-specific payment request payload
     * @param  array<string, string>  $opaque  flat string map of server correlation data
     * @param  string|null  $digest  RFC 9530 Content-Digest of the challenged request body, null when it had none
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
     * RFC 3339 form used both on the wire and in the binding's expires slot.
     */
    public function expiresParam(): string
    {
        return $this->expiresAt->toIso8601ZuluString();
    }

    /**
     * The binding's digest slot: an absent digest contributes the empty string,
     * so ids minted for body-less requests are unchanged by this parameter.
     */
    public function digestParam(): string
    {
        return $this->digest ?? '';
    }

    /**
     * This challenge as one `Payment …` header entry.
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
     * Amount in minor units, as carried in the rail request payload.
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
