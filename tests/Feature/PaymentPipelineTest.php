<?php

use Illuminate\Http\Request;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Exceptions\UnpriceableRequestException;
use Square1\Mpp\Payment\PaymentGate;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Settlement\TempoVerifier;
use Square1\Mpp\Tests\Fakes\AllowPrecondition;
use Square1\Mpp\Tests\Fakes\DenyPrecondition;
use Square1\Mpp\Tests\Fakes\FakeVerifier;
use Square1\Mpp\Tests\Fakes\TieredPricing;

beforeEach(function () {
    TieredPricing::reset();
    AllowPrecondition::reset();
    DenyPrecondition::reset();
    FakeVerifier::reset();
});

// Both middlewares reach the gate through one pipeline. These assert the steps
// that pipeline owns apply to a route however it was declared — the `mpp`
// middleware with arguments, `mpp` reading an attribute, or the automatic
// enforcer — since that is exactly what drifted before.

it('applies a global price resolver on every route style', function (string $uri) {
    config()->set('mpp.pricing.global', ['tiered']);
    TieredPricing::$overrides = ['amount' => '2.00'];

    $response = $this->get($uri)->assertStatus(402);

    expect(challengedAmount($response))->toBe('2.00');
})->with([
    'middleware arguments' => '/price/open',
    'attribute via mpp' => '/attr/explicit',
    'attribute auto-enforced' => '/attr/auto',
]);

it('runs a global precondition on every route style', function (string $uri) {
    config()->set('mpp.preconditions.global', ['deny']);

    $this->get($uri)->assertStatus(404);

    expect(DenyPrecondition::$calls)->toBe(1);
})->with([
    'middleware arguments' => '/price/open',
    'attribute via mpp' => '/attr/explicit',
    'attribute auto-enforced' => '/attr/auto',
]);

it('waives the charge on every route style', function (string $uri) {
    config()->set('mpp.pricing.global', ['tiered']);
    TieredPricing::$overrides = ['free' => true];

    $this->get($uri)->assertOk();

    expect(FakeVerifier::$calls)->toBe(0);
})->with([
    'middleware arguments' => '/price/open',
    'attribute via mpp' => '/attr/explicit',
    'attribute auto-enforced' => '/attr/auto',
]);

it('never reaches the gate for a waived request, so a broken rail cannot bite', function () {
    // /price/tempo offers a rail whose required config is stripped below, which
    // makes the gate throw before it mints. A waived request must not get that
    // far: it needs no payable rail because it is not paying.
    config()->set('mpp.methods.tempo.verifier', TempoVerifier::class);
    config()->set('mpp.methods.tempo.recipient', null);
    config()->set('mpp.methods.tempo.token', null);
    config()->set('mpp.methods.tempo.chain_id', null);

    TieredPricing::$overrides = ['free' => true];

    $this->withoutExceptionHandling()->get('/price/tempo')->assertOk()->assertSee('TEMPO');
});

it('still bails at the gate on that broken rail when the request is chargeable', function () {
    config()->set('mpp.methods.tempo.verifier', TempoVerifier::class);
    config()->set('mpp.methods.tempo.recipient', null);
    config()->set('mpp.methods.tempo.token', null);
    config()->set('mpp.methods.tempo.chain_id', null);

    $this->withoutExceptionHandling()->get('/price/tempo');
})->throws(InvalidConfigurationException::class, 'tempo');

it('rejects `mpp` used with neither arguments nor an attribute', function () {
    $this->withoutExceptionHandling();

    $this->get('/attr/missing');
})->throws(InvalidConfigurationException::class, 'has no #[RequiresPayment] attribute');

// A route may state no price at all and leave it to its resolvers. Something
// still has to supply one before the gate: the route, a global default, or a
// resolver. These pin which of those the pipeline accepts, and what it says when
// none of them came through.

it('lets a resolver own the price on a route that states none', function () {
    TieredPricing::$overrides = ['amount' => '2.00'];

    $response = $this->get('/price/resolver-owned')->assertStatus(402);

    expect(challengedAmount($response))->toBe('2.00')
        ->and(challengedOpaque($response, 'scope'))->toBe('price.owned');
});

it('waives an unpriced route without ever needing an amount', function () {
    TieredPricing::$overrides = ['free' => true];

    $this->get('/price/resolver-owned')->assertOk()->assertSee('OWNED');
});

it('refuses to serve when the resolver that owns the price declines', function () {
    TieredPricing::$overrides = null;

    try {
        $this->withoutExceptionHandling()->get('/price/resolver-owned');
        $this->fail('Expected the request to be refused as unpriceable.');
    } catch (UnpriceableRequestException $e) {
        expect($e->getMessage())
            ->toContain('No price for route [GET price/resolver-owned]')  // which route
            ->toContain('resolver(s) that ran (tiered) all declined')      // and why
            ->toContain("return `['free' => true]` rather than null");     // and the likely mistake
    }
});

it('reports a declined price as a request problem, not a config one', function () {
    TieredPricing::$overrides = null;

    // The config is valid — a resolver is registered and attached. What went
    // wrong depends on the request, and recurs in production long after deploy.
    $this->withoutExceptionHandling()->get('/price/resolver-owned');
})->throws(UnpriceableRequestException::class);

it('asks for an amount when there is no resolver to have declined', function () {
    $this->withoutExceptionHandling()->get('/price/nothing');
})->throws(UnpriceableRequestException::class, 'Give it an amount');

it('lets a global default price a route whose resolver declines', function () {
    config()->set('mpp.defaults.amount', '7.00');
    TieredPricing::$overrides = null;

    expect(challengedAmount($this->get('/price/resolver-owned')))->toBe('7.00');
});

it('never hands a precondition a null amount on a resolver-owned route', function () {
    config()->set('mpp.preconditions.global', ['allow']);
    TieredPricing::$overrides = ['amount' => '2.00'];

    $this->get('/price/resolver-owned')->assertStatus(402);

    expect(AllowPrecondition::$sawAmounts)->toBe(['2.00']);
});

it('refuses before the preconditions run, so no check sees an unpriced spec', function () {
    config()->set('mpp.preconditions.global', ['allow']);
    TieredPricing::$overrides = null;

    try {
        $this->withoutExceptionHandling()->get('/price/resolver-owned');
    } catch (UnpriceableRequestException) {
        // expected
    }

    expect(AllowPrecondition::$calls)->toBe(0);
});

it('refuses to charge a waived spec handed straight to the gate', function () {
    // The pipeline serves waived requests itself, so this cannot happen through
    // a route today. Asserted anyway: "the gate never sees a free spec" is an
    // assumption about callers, and a second entry path silently breaking that
    // kind of assumption is the bug this release already had to fix once.
    $spec = (new PaymentSpec(
        amount: '5.00',
        currency: 'USD',
        grants: 1,
        scope: 'direct.free',
        method: 'stripe',
        offeredMethods: ['stripe'],
    ))->with(['free' => true]);

    app(PaymentGate::class)->process(Request::create('/x'), fn () => response('SERVED'), $spec);
})->throws(InvalidConfigurationException::class, 'waived (free) spec reached the payment gate');

it('leaves an unattributed route in an auto-enforced group alone', function () {
    config()->set('mpp.pricing.global', ['tiered']);
    config()->set('mpp.preconditions.global', ['deny']);

    // Nothing to guard, so the pipeline is never entered — no pricing, no checks.
    $this->get('/attr/plain')->assertOk()->assertSee('FREE');

    expect(TieredPricing::$calls)->toBe(0)
        ->and(DenyPrecondition::$calls)->toBe(0);
});
