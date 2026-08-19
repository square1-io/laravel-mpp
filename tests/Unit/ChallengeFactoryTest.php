<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Protocol\AcceptPayment;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\ChallengeBinding;
use Square1\Mpp\Protocol\ChallengeFactory;
use Square1\Mpp\Support\Base64Url;

function specFor(array $methods, string $amount = '0.50'): PaymentSpec
{
    return new PaymentSpec(
        amount: $amount,
        currency: 'USD',
        grants: 1,
        scope: 'report.basic',
        method: $methods[0],
        offeredMethods: $methods,
    );
}

beforeEach(function () {
    config()->set('mpp.methods.stripe.network_id', 'profile_test');
    config()->set('mpp.methods.stripe.payment_method_types', ['card']);
    config()->set('mpp.methods.tempo.recipient', '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045');
    config()->set('mpp.methods.tempo.token', '0x20C000000000000000000000b9537d11c60E8b50');
    config()->set('mpp.methods.tempo.chain_id', 4217);

    $this->factory = new ChallengeFactory(new ChallengeBinding('test-secret'), ttl: 300);
});

it('mints a spec challenge whose id is the seven-slot binding', function () {
    $challenge = $this->factory->mint(specFor(['stripe']), 'api.test', 'stripe');

    expect($challenge->realm)->toBe('api.test')
        ->and($challenge->method)->toBe('stripe')
        ->and($challenge->intent)->toBe('charge')
        ->and($challenge->request['amount'])->toBe('50')
        ->and($challenge->request['currency'])->toBe('usd')
        ->and($challenge->request['methodDetails'])->toBe([
            'networkId' => 'profile_test',
            'paymentMethodTypes' => ['card'],
        ])
        ->and((new ChallengeBinding('test-secret'))->verify($challenge))->toBeTrue()
        ->and((new ChallengeBinding('other-secret'))->verify($challenge))->toBeFalse();
});

it('carries scope and metered grants in opaque, bound into the id', function () {
    $spec = new PaymentSpec(
        amount: '5.00', currency: 'USD', grants: 10, scope: 'clip',
        method: 'stripe', offeredMethods: ['stripe'],
    );

    $challenge = $this->factory->mint($spec, 'api.test', 'stripe');

    expect($challenge->opaque)->toMatchArray(['scope' => 'clip', 'grants' => '10'])
        ->and($challenge->grants())->toBe(10)
        ->and($challenge->isMetered())->toBeTrue()
        ->and($challenge->headerValue())->toContain('opaque="');
});

it('mints a unique nonce per challenge so ids never collide', function () {
    // Same spec, same realm, same method, SAME instant: without a nonce the
    // seven-slot binding is a pure function of these, so both ids would be
    // byte-identical. The nonce is what keeps them distinct — and keeps a
    // burned challenge from being resurrected by its own re-challenge.
    $now = CarbonImmutable::parse('2026-08-18T12:00:00Z');
    $spec = specFor(['stripe']);

    $a = $this->factory->mint($spec, 'api.test', 'stripe', now: $now);
    $b = $this->factory->mint($spec, 'api.test', 'stripe', now: $now);

    expect($a->opaque)->toHaveKey('nonce')
        ->and($a->opaque['nonce'])->not->toBe($b->opaque['nonce'])
        ->and($a->id)->not->toBe($b->id)
        ->and((new ChallengeBinding('test-secret'))->verify($a))->toBeTrue()
        ->and((new ChallengeBinding('test-secret'))->verify($b))->toBeTrue();
});

it('renders spec wire params with a decodable base64url request', function () {
    $challenge = $this->factory->mint(specFor(['stripe']), 'api.test', 'stripe');
    $header = $challenge->headerValue();

    expect($header)->toStartWith('Payment id="')
        ->toContain('realm="api.test"')
        ->toContain('method="stripe"')
        ->toContain('intent="charge"')
        ->toContain('expires="');

    preg_match('/request="([^"]+)"/', $header, $m);
    $decoded = json_decode((string) Base64Url::decode($m[1]), true);

    expect($decoded)->toBe([
        'amount' => '50',
        'currency' => 'usd',
        'methodDetails' => ['networkId' => 'profile_test', 'paymentMethodTypes' => ['card']],
    ]);
});

it('mints one challenge per offered method with distinct ids', function () {
    $challenges = $this->factory->mintAll(specFor(['stripe', 'tempo']), 'api.test', AcceptPayment::parse(null));

    expect($challenges)->toHaveCount(2)
        ->and($challenges[0]->method)->toBe('stripe')
        ->and($challenges[1]->method)->toBe('tempo')
        ->and($challenges[0]->id)->not->toBe($challenges[1]->id)
        ->and($challenges[1]->request['currency'])->toBe('0x20C000000000000000000000b9537d11c60E8b50')
        ->and($challenges[1]->request['amount'])->toBe('500000');
});

it('advertises only the pull submission mode on a tempo challenge', function () {
    // TempoVerifier accepts a type="transaction" credential and nothing else, so
    // the challenge must not let a client infer that push (type="hash", client
    // broadcasts) would settle — omitting supportedModes means "both".
    $challenge = $this->factory->mint(specFor(['tempo']), 'api.test', 'tempo');

    expect($challenge->request['methodDetails']['chainId'])->toBe(4217)
        ->and($challenge->request['methodDetails']['supportedModes'])->toBe(['pull'])
        // A random per-challenge bytes32 memo the paid transfer must echo.
        ->and($challenge->request['methodDetails']['memo'])->toMatch('/^0x[0-9a-f]{64}$/');
});

it('mints a unique tempo memo per challenge', function () {
    $a = $this->factory->mint(specFor(['tempo']), 'api.test', 'tempo');
    $b = $this->factory->mint(specFor(['tempo']), 'api.test', 'tempo');

    expect($a->request['methodDetails']['memo'])
        ->not->toBe($b->request['methodDetails']['memo']);
});

it('honours Accept-Payment ranking and exclusion when minting', function () {
    $spec = specFor(['stripe', 'tempo']);

    $ranked = $this->factory->mintAll($spec, 'api.test', AcceptPayment::parse('tempo/charge, stripe/charge;q=0.2'));
    $filtered = $this->factory->mintAll($spec, 'api.test', AcceptPayment::parse('tempo/charge, stripe/charge;q=0'));

    expect(array_map(fn ($c) => $c->method, $ranked))->toBe(['tempo', 'stripe'])
        ->and(array_map(fn ($c) => $c->method, $filtered))->toBe(['tempo']);
});

it('comma-combines multiple challenges into one header value', function () {
    $challenges = $this->factory->mintAll(specFor(['stripe', 'tempo']), 'api.test', AcceptPayment::parse(null));
    $header = $this->factory->wwwAuthenticate($challenges);

    $parsed = parseChallenges($header);

    expect($parsed)->toHaveCount(2)
        ->and($parsed[0]['method'])->toBe('stripe')
        ->and($parsed[1]['method'])->toBe('tempo')
        ->and($parsed[0]['id'])->toBe($challenges[0]->id);
});

it('honours the ttl in expires', function () {
    $now = CarbonImmutable::parse('2026-08-18T12:00:00Z');
    $challenge = (new ChallengeFactory(new ChallengeBinding('s'), ttl: 120))
        ->mint(specFor(['stripe']), 'api.test', 'stripe', now: $now);

    expect($challenge->expiresParam())->toBe('2026-08-18T12:02:00Z');
});

it('leaves the digest null when the challenged request had no body', function () {
    $challenge = $this->factory->mint(specFor(['stripe']), 'api.test', 'stripe');

    expect($challenge->digest)->toBeNull()
        ->and($challenge->digestParam())->toBe('')
        ->and($challenge->headerValue())->not->toContain('digest="');
});

it('binds a body digest into the id and emits it on the wire', function () {
    $digest = 'sha-256=:X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=:';
    $now = CarbonImmutable::parse('2026-08-18T12:00:00Z');
    $spec = specFor(['stripe']);

    $bound = $this->factory->mint($spec, 'api.test', 'stripe', digest: $digest, now: $now);

    expect($bound->digest)->toBe($digest)
        ->and((new ChallengeBinding('test-secret'))->verify($bound))->toBeTrue()
        ->and($bound->headerValue())->toContain('digest="'.$digest.'"');

    // The digest sits between expires and opaque, per the spec's parameter order.
    expect($bound->headerValue())->toMatch('/expires="[^"]+", digest="/');
});

it('gives every rail in one 402 the same body digest', function () {
    // The digest describes the request body, not the settlement method, so a
    // client may pick any offered rail and still satisfy the body check.
    $digest = 'sha-256=:X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=:';

    $challenges = $this->factory->mintAll(
        specFor(['stripe', 'tempo']), 'api.test', AcceptPayment::parse(null), $digest,
    );

    expect(array_map(fn ($c) => $c->digest, $challenges))->toBe([$digest, $digest])
        ->and($challenges[0]->id)->not->toBe($challenges[1]->id);
});

it('binds the minted-for resource into opaque', function () {
    // Scope cannot stand in for the route: one scope may cover several routes at
    // several prices, so a challenge bought at the cheap one must not settle at
    // the dear one. `resource` rides in opaque, which is already bound (slot 7).
    $now = CarbonImmutable::parse('2026-08-18T12:00:00Z');
    $spec = specFor(['stripe']);

    $bound = $this->factory->mint($spec, 'api.test', 'stripe', resource: 'GET report/basic', now: $now);

    expect($bound->opaque['resource'])->toBe('GET report/basic')
        ->and((new ChallengeBinding('test-secret'))->verify($bound))->toBeTrue()
        ->and($bound->headerValue())->toContain('opaque="');
});

it('changes the id when the resource changes and omits the key when absent', function () {
    // Same nonce is impossible to force, so compare ids the only way that
    // isolates the resource: recompute the binding over each opaque map.
    $binding = new ChallengeBinding('test-secret');
    $now = CarbonImmutable::parse('2026-08-18T12:00:00Z');
    $spec = specFor(['stripe']);

    $cheap = $this->factory->mint($spec, 'api.test', 'stripe', resource: 'GET report/basic', now: $now);
    $dear = new Challenge(
        id: '', realm: $cheap->realm, method: $cheap->method, intent: $cheap->intent,
        request: $cheap->request, expiresAt: $cheap->expiresAt,
        opaque: ['resource' => 'GET report/premium'] + $cheap->opaque,
        digest: $cheap->digest,
    );

    expect($binding->idFor($dear))->not->toBe($cheap->id);

    $unscoped = $this->factory->mint($spec, 'api.test', 'stripe', now: $now);

    expect($unscoped->opaque)->not->toHaveKey('resource')
        ->and($binding->verify($unscoped))->toBeTrue();
});

it('gives every rail in one 402 the same resource', function () {
    $challenges = $this->factory->mintAll(
        specFor(['stripe', 'tempo']), 'api.test', AcceptPayment::parse(null), null, 'POST invoices',
    );

    expect(array_map(fn ($c) => $c->opaque['resource'], $challenges))->toBe(['POST invoices', 'POST invoices']);
});

it('builds a spec problem document without an accepts list', function () {
    $challenges = $this->factory->mintAll(specFor(['stripe', 'tempo']), 'api.test', AcceptPayment::parse(null));
    $doc = $this->factory->problemDocument($challenges);

    expect($doc['status'])->toBe(402)
        ->and($doc['type'])->toBe('https://paymentauth.org/problems/payment-required')
        ->and($doc['challengeId'])->toBe($challenges[0]->id)
        ->and($doc)->not->toHaveKey('accepts');
});

it('emits each registered problem type with the draft url title and status', function (string $slug, string $title, int $status) {
    $doc = $this->factory->problemDocument([], null, $slug);

    expect($doc['type'])->toBe("https://paymentauth.org/problems/{$slug}")
        ->and($doc['title'])->toBe($title)
        ->and($doc['status'])->toBe($status)
        ->and($doc['detail'])->toBeString()->not->toBe('');
})->with([
    // The core draft's error table: every type is 402 except method-unsupported,
    // because the rest ship a fresh challenge the client can still pay.
    ['payment-required', 'Payment Required', 402],
    ['payment-insufficient', 'Payment Insufficient', 402],
    ['payment-expired', 'Payment Expired', 402],
    ['verification-failed', 'Verification Failed', 402],
    ['method-unsupported', 'Method Unsupported', 400],
    ['malformed-credential', 'Malformed Credential', 402],
    ['invalid-challenge', 'Invalid Challenge', 402],
]);

it('omits challengeId when the response names no challenge', function () {
    $doc = $this->factory->problemDocument([], 'Credential could not be parsed.', 'malformed-credential');

    expect($doc)->not->toHaveKey('challengeId')
        ->and($doc['detail'])->toBe('Credential could not be parsed.');
});

it('names the primary challenge on a typed rejection that carries one', function () {
    $challenges = $this->factory->mintAll(specFor(['stripe']), 'api.test', AcceptPayment::parse(null));
    $doc = $this->factory->problemDocument($challenges, null, 'verification-failed');

    expect($doc['challengeId'])->toBe($challenges[0]->id)
        ->and($doc['type'])->toBe('https://paymentauth.org/problems/verification-failed');
});

it('falls back to payment-required for an unregistered type', function () {
    $doc = $this->factory->problemDocument([], null, 'not-a-registered-problem');

    expect($doc['type'])->toBe('https://paymentauth.org/problems/payment-required')
        ->and($doc['status'])->toBe(402);
});
