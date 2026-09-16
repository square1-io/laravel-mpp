<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Protocol\Requests\FiatRequestBuilder;
use Square1\Mpp\Protocol\Requests\RailRequestBuilder;
use Square1\Mpp\Protocol\Requests\TempoRequestBuilder;

/**
 * Mints challenges in the spec format, one Challenge per offered method.
 *
 * Each challenge authenticates itself through the seven-slot HMAC binding that
 * forms its id. The class also renders the `WWW-Authenticate` field lines and
 * the problem+json body for a 402 response.
 */
class ChallengeFactory
{
    public function __construct(
        private readonly ChallengeBinding $binding,
        private readonly int $ttl = 300,
    ) {}

    /**
     * Mints one challenge per offered method, in the Accept-Payment order of
     * the caller.
     *
     * The method always returns at least the set that the server prefers. The
     * spec states that the server ignores an Accept-Payment header that matches
     * nothing.
     *
     * `$digest` is the RFC 9530 Content-Digest of the challenged body.
     * `$resource` is the route that the server mints the challenge for. Both
     * describe the request and not the settlement method, so both are the same
     * for every rail in one 402.
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

        // `resource` travels in opaque and does not get a binding slot of its
        // own. Opaque is already slot 7, so the id binds anything that the
        // server places here, and a conformant client echoes it. A new slot
        // would change the HMAC of every challenge that omits the value. Scope
        // cannot replace the resource, because one scope can cover several
        // routes at several prices. A challenge that a client bought at the
        // cheap route would then settle at the expensive one.
        $opaque = array_filter([
            'scope' => $spec->scope,
            'grants' => $spec->grants > 1 ? (string) $spec->grants : null,
            'resource' => $resource,
        ], fn ($v) => $v !== null && $v !== '');

        // A random nonce per mint makes the challenge id unique. Without it, the
        // id is a function of realm|method|intent|request|expires|opaque alone.
        // Two 402 responses for the same route and price in the same second
        // would then share an id. A new challenge after a burn would also return
        // the burned id, and one payment could settle twice. The nonce travels
        // in the `opaque` slot of the spec, so the id binds it (slot 7) and a
        // conformant client echoes it without a change.
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
     * Returns one WWW-Authenticate field line per challenge, in the offered
     * order.
     *
     * The method emits repeated field lines, and not one value joined by
     * commas. The spec illustrates that form (draft-httpauth-payment-00, B.2),
     * and a parser can read it without ambiguity.
     *
     * Both forms are legal HTTP. WWW-Authenticate is a list field, and RFC 9110
     * allows either form. In a joined value, the commas between challenges sit
     * in the same position as the commas between the auth-params of one
     * challenge. A parser must then infer where each challenge ends.
     *
     * A 402 with one challenge emits exactly one line, as before.
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
     * `https://paymentauth.org/problems/<slug>`.
     *
     * The error table of the core draft lists them. Every type except
     * `method-unsupported` is a 402. The response carries a fresh challenge, so
     * the request still requires payment. That includes a malformed credential,
     * which the draft records as a 402 and not a 400.
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
     * Returns the RFC 9457 body for a rejection.
     *
     * `challengeId` names the primary challenge. The method omits the field when
     * the response carries no challenge to name.
     *
     * An unregistered `$type` falls back to `payment-required`. The method does
     * not invent a problem URL that a client cannot look up.
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
     * Returns the configured builder for a rail, or the default builder for
     * that rail.
     *
     * The discovery generator calls the same method, so both derive the request
     * shape of a rail in the same way.
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
