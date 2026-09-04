<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Protocol\Requests\FiatRequestBuilder;
use Square1\Mpp\Protocol\Requests\RailRequestBuilder;
use Square1\Mpp\Protocol\Requests\TempoRequestBuilder;

/**
 * Mints spec-format challenges — one Challenge per offered method, each
 * self-authenticating via the seven-slot HMAC binding (its id) — and renders
 * the `WWW-Authenticate` field lines and problem+json body for a 402.
 */
class ChallengeFactory
{
    public function __construct(
        private readonly ChallengeBinding $binding,
        private readonly int $ttl = 300,
    ) {}

    /**
     * Mint a challenge per offered method, honouring the caller's
     * Accept-Payment ranking. Always returns at least the server-preferred
     * set: an Accept-Payment that matches nothing is ignored per spec.
     *
     * `$digest` (the challenged body's RFC 9530 Content-Digest) and `$resource`
     * (the route the challenge was minted for) describe the request, not the
     * settlement method, so both are identical across every rail in one 402.
     *
     * @return non-empty-list<Challenge>
     */
    public function mintAll(
        PaymentSpec $spec,
        string $realm,
        AcceptPayment $accept,
        ?string $digest = null,
        ?string $resource = null,
        ?CarbonImmutable $now = null,
    ): array {
        $intent = 'charge';
        $methods = $accept->rank($spec->offeredMethods, $intent);

        return array_map(
            fn (string $method) => $this->mint($spec, $realm, $method, $intent, $digest, $resource, $now),
            $methods,
        );
    }

    public function mint(
        PaymentSpec $spec,
        string $realm,
        string $method,
        string $intent = 'charge',
        ?string $digest = null,
        ?string $resource = null,
        ?CarbonImmutable $now = null,
    ): Challenge {
        $now ??= CarbonImmutable::now();
        $config = (array) config("mpp.methods.{$method}", []);

        // `resource` rides in opaque rather than earning a binding slot of its
        // own: opaque is already slot 7, so anything placed here is bound into
        // the id and echoed back, and adding a slot would change the HMAC of
        // every challenge that omits it. Scope alone cannot stand in for it —
        // one scope may cover several routes at several prices, so a challenge
        // bought at the cheap one would settle at the dear one.
        $opaque = array_filter([
            'scope' => $spec->scope,
            'grants' => $spec->grants > 1 ? (string) $spec->grants : null,
            'resource' => $resource,
        ], fn ($v) => $v !== null && $v !== '');

        // A per-mint random nonce makes the challenge id unique. Without it the
        // id is a pure function of realm|method|intent|request|expires|opaque,
        // so two 402s minted for the same route+price in the same wall-clock
        // second collide — and a re-challenge after a burn would resurrect the
        // burned id, letting one payment settle twice. The nonce rides in the
        // spec's `opaque` slot, so it is bound into the id (slot 7) and echoed
        // unchanged by conformant clients.
        $opaque['nonce'] = bin2hex(random_bytes(16));

        $unbound = new Challenge(
            id: '',
            realm: $realm,
            method: $method,
            intent: $intent,
            request: self::builderFor($method, $config)->build($spec, $config),
            expiresAt: $now->addSeconds($this->ttl),
            opaque: $opaque,
            digest: $digest,
        );

        return $unbound->withId($this->binding->idFor($unbound));
    }

    /**
     * One WWW-Authenticate field line per challenge, in offered order.
     *
     * Repeated field lines rather than a single comma-joined value: that is the
     * form the spec illustrates (draft-httpauth-payment-00, B.2), and it is the
     * unambiguous one to parse. Both are legal HTTP — WWW-Authenticate is a list
     * field, so RFC 9110 allows either — but a joined value puts the commas
     * separating challenges in the same position as the commas separating each
     * challenge's own auth-params, leaving a parser to infer the boundaries.
     *
     * A single-challenge 402 emits exactly one line, unchanged from before.
     *
     * @param  non-empty-list<Challenge>  $challenges
     * @return non-empty-list<string>
     */
    public function wwwAuthenticateLines(array $challenges): array
    {
        return array_values(array_map(fn (Challenge $c) => $c->headerValue(), $challenges));
    }

    /**
     * The registered problem types, keyed by the slug that completes
     * `https://paymentauth.org/problems/<slug>`, as listed in the core draft's
     * error table. All but `method-unsupported` are 402: the response carries a
     * fresh challenge, so the request is still "payment required" — including a
     * malformed credential, which the draft scores 402 rather than 400.
     *
     * @var array<string, array{title: string, status: int, detail: string}>
     */
    private const PROBLEMS = [
        'payment-required' => [
            'title' => 'Payment Required',
            'status' => 402,
            'detail' => 'Payment is required.',
        ],
        'payment-insufficient' => [
            'title' => 'Payment Insufficient',
            'status' => 402,
            'detail' => 'The amount paid is below the amount challenged.',
        ],
        'payment-expired' => [
            'title' => 'Payment Expired',
            'status' => 402,
            'detail' => 'The challenge or authorization has expired.',
        ],
        'verification-failed' => [
            'title' => 'Verification Failed',
            'status' => 402,
            'detail' => 'The payment proof could not be verified.',
        ],
        'method-unsupported' => [
            'title' => 'Method Unsupported',
            'status' => 400,
            'detail' => 'The requested settlement method is not accepted.',
        ],
        'malformed-credential' => [
            'title' => 'Malformed Credential',
            'status' => 402,
            'detail' => 'The Payment credential could not be parsed.',
        ],
        'invalid-challenge' => [
            'title' => 'Invalid Challenge',
            'status' => 402,
            'detail' => 'The challenge is unknown, expired, or already used.',
        ],
    ];

    /**
     * The RFC 9457 body for a rejection. `challengeId` names the primary
     * challenge, and is omitted when the response carries none to name.
     *
     * An unregistered `$type` falls back to `payment-required` rather than
     * inventing a problem URL a client cannot look up.
     *
     * @param  list<Challenge>  $challenges
     * @return array<string, mixed>
     */
    public function problemDocument(array $challenges, ?string $detail = null, string $type = 'payment-required'): array
    {
        $problem = self::PROBLEMS[$type] ?? self::PROBLEMS['payment-required'];
        $slug = isset(self::PROBLEMS[$type]) ? $type : 'payment-required';

        $document = [
            'type' => 'https://paymentauth.org/problems/'.$slug,
            'title' => $problem['title'],
            'status' => $problem['status'],
            'detail' => $detail ?? $problem['detail'],
        ];

        if ($challenges !== []) {
            $document['challengeId'] = $challenges[0]->id;
        }

        return $document;
    }

    /**
     * The configured builder for a rail, else the rail default. Shared with the
     * discovery generator so both derive a rail's request shape identically.
     *
     * @param  array<string, mixed>  $config
     */
    public static function builderFor(string $method, array $config): RailRequestBuilder
    {
        $class = $config['request_builder'] ?? match ($method) {
            'tempo' => TempoRequestBuilder::class,
            default => FiatRequestBuilder::class,
        };

        return app($class);
    }
}
