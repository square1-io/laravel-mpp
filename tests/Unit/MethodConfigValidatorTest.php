<?php

use Illuminate\Support\Facades\Log;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\MethodConfigValidator;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Settlement\StripeVerifier;
use Square1\Mpp\Settlement\TempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeTempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeVerifier;

beforeEach(function () {
    $this->validator = new MethodConfigValidator;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function completeTempoConfig(array $overrides = []): array
{
    return array_merge([
        'verifier' => TempoVerifier::class,
        'recipient' => '0x0dcd39a3f85aa288c1b2825bc41eb7e9bb2abf70',
        'token' => '0x20c0000000000000000000000000000000000000',
        'chain_id' => 42431,
        'rpc_url' => 'https://rpc.moderato.tempo.xyz',
    ], $overrides);
}

it('passes a fully configured Tempo rail', function () {
    config()->set('mpp.methods.tempo', completeTempoConfig());

    expect(fn () => $this->validator->validateMethod('tempo'))
        ->not->toThrow(InvalidConfigurationException::class);
});

it('fails fast when the Tempo rail is missing its recipient', function () {
    config()->set('mpp.methods.tempo', completeTempoConfig(['recipient' => null]));

    expect(fn () => $this->validator->validateMethod('tempo'))
        ->toThrow(InvalidConfigurationException::class, 'recipient (TEMPO_RECIPIENT)');
});

it('fails fast when the Tempo rail is missing its token and chain id', function () {
    config()->set('mpp.methods.tempo', [
        'verifier' => TempoVerifier::class,
        'recipient' => '0xabc',
    ]);

    expect(fn () => $this->validator->validateMethod('tempo'))
        ->toThrow(InvalidConfigurationException::class, 'token');
});

it('treats a zero chain id as missing', function () {
    config()->set('mpp.methods.tempo', completeTempoConfig(['chain_id' => 0]));

    expect(fn () => $this->validator->validateMethod('tempo'))
        ->toThrow(InvalidConfigurationException::class, 'chain_id');
});

it('accepts `currency` as an alias for the Tempo token', function () {
    $config = completeTempoConfig();
    unset($config['token']);
    $config['currency'] = '0x20c0000000000000000000000000000000000000';
    config()->set('mpp.methods.tempo', $config);

    expect(fn () => $this->validator->validateMethod('tempo'))
        ->not->toThrow(InvalidConfigurationException::class);
});

it('accepts `rpc` as an alias for the Tempo rpc_url', function () {
    $config = completeTempoConfig();
    unset($config['rpc_url']);
    $config['rpc'] = 'https://rpc.moderato.tempo.xyz';
    Log::spy();
    config()->set('mpp.methods.tempo', $config);

    $this->validator->validateMethod('tempo');

    Log::shouldNotHaveReceived('warning');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function completeStripeConfig(array $overrides = []): array
{
    return array_merge([
        'verifier' => StripeVerifier::class,
        'secret_key' => 'sk_test_x',
        'network_id' => 'profile_test',
        'payment_method_types' => ['card'],
    ], $overrides);
}

it('warns but never throws when the Stripe rail lacks only its secret key', function () {
    Log::spy();
    config()->set('mpp.methods.stripe', completeStripeConfig(['secret_key' => null]));

    // The key is a settle-time secret; the 402 is still well-formed and payable.
    expect(fn () => $this->validator->validateMethod('stripe'))
        ->not->toThrow(InvalidConfigurationException::class);

    Log::shouldHaveReceived('warning')->once();
});

it('fails fast when the Stripe rail is missing its network id', function () {
    // Without network_id the minted 402 omits a methodDetails member the Stripe
    // charge method requires, and no wallet can scope an SPT to this seller.
    config()->set('mpp.methods.stripe', completeStripeConfig(['network_id' => null]));

    expect(fn () => $this->validator->validateMethod('stripe'))
        ->toThrow(InvalidConfigurationException::class, 'network_id (STRIPE_NETWORK_ID)');
});

it('fails fast when the Stripe rail has no payment method types', function () {
    config()->set('mpp.methods.stripe', completeStripeConfig(['payment_method_types' => []]));

    expect(fn () => $this->validator->validateMethod('stripe'))
        ->toThrow(InvalidConfigurationException::class, 'payment_method_types');
});

it('rejects a scalar where the wire needs a list of payment method types', function () {
    config()->set('mpp.methods.stripe', completeStripeConfig(['payment_method_types' => 'card']));

    expect(fn () => $this->validator->validateMethod('stripe'))
        ->toThrow(InvalidConfigurationException::class, 'payment_method_types');
});

it('fails fast when a stripe rail is configured with neither wire requirement', function () {
    config()->set('mpp.methods.stripe', ['verifier' => StripeVerifier::class]);

    expect(fn () => $this->validator->validateMethod('stripe'))
        ->toThrow(InvalidConfigurationException::class, 'network_id (STRIPE_NETWORK_ID), payment_method_types');
});

it('treats an unexpanded env placeholder as missing', function () {
    Log::spy();
    config()->set('mpp.methods.stripe', completeStripeConfig(['secret_key' => '${STRIPE_SECRET_KEY}']));

    $this->validator->validateMethod('stripe');

    Log::shouldHaveReceived('warning')->once(); // secret_key only; network_id is set
});

it('logs each recommended-config warning only once per process', function () {
    Log::spy();
    config()->set('mpp.methods.stripe', completeStripeConfig(['secret_key' => null]));

    $this->validator->validateMethod('stripe');
    $this->validator->validateMethod('stripe');

    Log::shouldHaveReceived('warning')->once(); // not twice
});

it('skips validation entirely for a custom verifier', function () {
    Log::spy();
    config()->set('mpp.methods.custom', ['verifier' => 'App\\Settlement\\MyVerifier']);

    expect(fn () => $this->validator->validateMethod('custom'))
        ->not->toThrow(InvalidConfigurationException::class);

    Log::shouldNotHaveReceived('warning');
});

it('validates the offered rail config through validate()', function () {
    config()->set('mpp.methods.tempo', ['verifier' => TempoVerifier::class]); // incomplete

    $spec = new PaymentSpec(
        amount: '0.01',
        currency: 'USD',
        grants: 1,
        scope: 'x',
        method: 'tempo',
        offeredMethods: ['tempo'],
    );

    expect(fn () => $this->validator->validate($spec))
        ->toThrow(InvalidConfigurationException::class, "'tempo' payment rail");
});

it('allows stripe and tempo to be co-offered on the unified spec wire', function () {
    config()->set('mpp.methods.stripe', completeStripeConfig());
    config()->set('mpp.methods.tempo.verifier', TempoVerifier::class); // recipient/token/chain_id from TestCase

    $spec = new PaymentSpec(
        amount: '0.50', currency: 'USD', grants: 1, scope: 'x',
        method: 'stripe', offeredMethods: ['stripe', 'tempo'],
    );

    // v1 forbade this (two wire dialects); v2 mints one spec-format challenge
    // per rail in a single 402, so the restriction is gone.
    expect(fn () => $this->validator->validate($spec))
        ->not->toThrow(InvalidConfigurationException::class);
});

it('allows the mppx rail as the sole offered method', function () {
    config()->set('mpp.methods.tempo.verifier', TempoVerifier::class); // recipient/token/chain_id from TestCase

    $spec = new PaymentSpec(
        amount: '0.01', currency: 'USD', grants: 1, scope: 'x',
        method: 'tempo', offeredMethods: ['tempo'],
    );

    expect(fn () => $this->validator->validate($spec))
        ->not->toThrow(InvalidConfigurationException::class);
});

it('allows several native rails to be co-offered', function () {
    config()->set('mpp.methods.stripe', completeStripeConfig());
    config()->set('mpp.methods.other', ['verifier' => 'App\\Settlement\\OtherVerifier']);

    $spec = new PaymentSpec(
        amount: '0.50', currency: 'USD', grants: 1, scope: 'x',
        method: 'stripe', offeredMethods: ['stripe', 'other'],
    );

    expect(fn () => $this->validator->validate($spec))
        ->not->toThrow(InvalidConfigurationException::class);
});

it('validates a custom verifier under the tempo name without rail-specific rules', function () {
    // Custom verifiers own their config; only shipped rails get keyed rules.
    config()->set('mpp.methods.tempo.verifier', FakeTempoVerifier::class);

    $spec = new PaymentSpec(
        amount: '0.50', currency: 'USD', grants: 1, scope: 'x',
        method: 'stripe', offeredMethods: ['stripe', 'tempo'],
    );

    expect(fn () => $this->validator->validate($spec))
        ->not->toThrow(InvalidConfigurationException::class);
});

// ── method identifier format (core draft: 1*LOWERALPHA) ──────────────────────

it('rejects a method identifier that is not lowercase ASCII letters', function (string $method) {
    // The identifier lands on the wire as the challenge `method`, so a
    // non-conformant name must fail before it can mint a challenge — even for a
    // custom rail the rule table does not know.
    config()->set("mpp.methods.{$method}.verifier", FakeVerifier::class);

    expect(fn () => $this->validator->validateMethod($method))
        ->toThrow(InvalidConfigurationException::class, 'lowercase ASCII letters');
})->with([
    'uppercase' => 'AcmePay',
    'digit' => 'acme2',
    'hyphen' => 'acme-pay',
    'empty' => '',
]);

it('accepts an all-lowercase custom method identifier', function () {
    config()->set('mpp.methods.acmepay.verifier', FakeVerifier::class);

    expect(fn () => $this->validator->validateMethod('acmepay'))
        ->not->toThrow(InvalidConfigurationException::class);
});
