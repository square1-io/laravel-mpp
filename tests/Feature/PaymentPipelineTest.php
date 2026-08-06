<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
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

    expect($response->json('accepts.0.amount'))->toBe('2.00');
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

it('leaves an unattributed route in an auto-enforced group alone', function () {
    config()->set('mpp.pricing.global', ['tiered']);
    config()->set('mpp.preconditions.global', ['deny']);

    // Nothing to guard, so the pipeline is never entered — no pricing, no checks.
    $this->get('/attr/plain')->assertOk()->assertSee('FREE');

    expect(TieredPricing::$calls)->toBe(0)
        ->and(DenyPrecondition::$calls)->toBe(0);
});
