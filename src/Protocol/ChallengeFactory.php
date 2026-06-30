<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Mints, signs and encodes payment challenges.
 *
 * The signature is an HMAC over the binding fields (id, amount, currency,
 * method, network_id, grants, scope, expiresAt) so a client cannot alter the
 * price or grant count between the 402 and the paid retry, and a challenge
 * signed under a rotated secret will no longer verify.
 *
 * A challenge may offer several settlement methods. Each offered method gets its
 * OWN signature over the SAME shared economic fields but THAT method's
 * method/network_id, so a signature minted for one method does not validate
 * another method's accept entry (the signature stays load-bearing per method).
 * The primary method's canonical string and signature are byte-identical to a
 * single-method challenge, so the wire shape is unchanged when only one method
 * is offered.
 */
class ChallengeFactory
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl = 300,
    ) {
        if ($this->secret === '') {
            throw new InvalidConfigurationException(
                'mpp.secret is not set. Provide MPP_CHALLENGE_SECRET before minting challenges.'
            );
        }
    }

    /**
     * @param  array{method?:string,amount:string,currency:string,grants?:int,scope:string,networkId?:?string,paymentMethodTypes?:list<string>,intent?:string,offers?:list<ChallengeOffer>}  $spec
     */
    public function mint(array $spec, ?CarbonImmutable $now = null): Challenge
    {
        $now ??= CarbonImmutable::now();

        return new Challenge(
            id: 'chal_'.Str::ulid(),
            method: $spec['method'] ?? 'stripe',
            amount: (string) $spec['amount'],
            currency: strtoupper($spec['currency']),
            grants: (int) ($spec['grants'] ?? 1),
            scope: $spec['scope'],
            expiresAt: $now->addSeconds($this->ttl),
            networkId: $spec['networkId'] ?? null,
            paymentMethodTypes: $spec['paymentMethodTypes'] ?? ['card'],
            intent: $spec['intent'] ?? 'charge',
            offers: $spec['offers'] ?? [],
        );
    }

    /**
     * Sign the challenge's PRIMARY method. Byte-identical to a single-method
     * challenge's signature.
     */
    public function sign(Challenge $challenge): string
    {
        return $this->signOffer($challenge, $challenge->primaryOffer());
    }

    /**
     * Verify a signature against the challenge's PRIMARY method.
     */
    public function verify(Challenge $challenge, string $signature): bool
    {
        return hash_equals($this->sign($challenge), $signature);
    }

    /**
     * Sign one offered method. The HMAC binds the shared economic fields plus
     * THIS offer's method + network_id, so the signature is method-specific.
     */
    public function signOffer(Challenge $challenge, ChallengeOffer $offer): string
    {
        return hash_hmac('sha256', $this->canonicalFor($challenge, $offer), $this->secret);
    }

    /**
     * Verify a signature against a specific offered method. Returns false if the
     * method is not offered by this challenge or the signature does not match.
     */
    public function verifyOffer(Challenge $challenge, string $method, string $signature): bool
    {
        $offer = $challenge->offerFor($method);

        if ($offer === null) {
            return false;
        }

        return hash_equals($this->signOffer($challenge, $offer), $signature);
    }

    private function canonicalFor(Challenge $challenge, ChallengeOffer $offer): string
    {
        return implode('|', [
            $challenge->id,
            $challenge->amount,
            $challenge->currency,
            $offer->method,
            $offer->networkId ?? '',
            (string) $challenge->grants,
            $challenge->scope,
            $challenge->expiresAt->toIso8601ZuluString(),
        ]);
    }

    /**
     * Encode the `WWW-Authenticate: Payment ...` header value.
     */
    public function wwwAuthenticate(Challenge $challenge): string
    {
        // The header advertises the PRIMARY method (back-compat); additional
        // offered methods are advertised in the problem+json `accepts[]`. The
        // header lists the full set of offered method names under `methods` so a
        // header-only client can see there are alternatives.
        $parts = [
            'id' => $challenge->id,
            'method' => $challenge->method,
            'intent' => $challenge->intent,
            'amount' => $challenge->amount,
            'currency' => $challenge->currency,
            'network_id' => $challenge->networkId,
            'payment_method_types' => implode(' ', $challenge->paymentMethodTypes),
            'grants' => (string) $challenge->grants,
            'scope' => $challenge->scope,
            'expires_at' => $challenge->expiresAt->toIso8601ZuluString(),
            'sig' => $this->sign($challenge),
        ];

        // Only advertise the `methods` hint when more than one is offered, so a
        // single-method header is byte-identical to before.
        if ($challenge->offers !== []) {
            $methods = array_map(fn ($offer) => $offer->method, $challenge->allOffers());
            $parts['methods'] = implode(' ', $methods);
        }

        $encoded = [];
        foreach ($parts as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $encoded[] = sprintf('%s="%s"', $key, $value);
        }

        return 'Payment '.implode(', ', $encoded);
    }

    /**
     * The application/problem+json body mirroring the challenge for JSON clients.
     *
     * @return array<string, mixed>
     */
    public function problemDocument(Challenge $challenge): array
    {
        // One signed accepts[] entry per offered method, primary first. For a
        // single-method challenge this is exactly one entry, identical to before.
        $accepts = [];
        foreach ($challenge->allOffers() as $offer) {
            $accepts[] = [
                'method' => $offer->method,
                'amount' => $challenge->amount,
                'currency' => $challenge->currency,
                'network_id' => $offer->networkId,
                'payment_method_types' => $offer->paymentMethodTypes,
                'grants' => $challenge->grants,
                'scope' => $challenge->scope,
                'expiresAt' => $challenge->expiresAt->toIso8601ZuluString(),
                'sig' => $this->signOffer($challenge, $offer),
            ];
        }

        return [
            'type' => 'https://paymentauth.org/problems/payment-required',
            'title' => 'Payment Required',
            'status' => 402,
            'detail' => 'Payment is required to access this resource.',
            'challengeId' => $challenge->id,
            'accepts' => $accepts,
        ];
    }
}
