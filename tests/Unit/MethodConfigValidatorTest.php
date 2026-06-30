<?php

use Illuminate\Support\Facades\Log;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\MethodConfigValidator;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Settlement\StripeVerifier;
use Square1\Mpp\Settlement\TempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeTempoVerifier;

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

it('warns but never throws when the Stripe rail lacks a secret key or network id', function () {
    Log::spy();
    config()->set('mpp.methods.stripe', [
        'verifier' => StripeVerifier::class,
        'secret_key' => null,
        'network_id' => null,
    ]);

    expect(fn () => $this->validator->validateMethod('stripe'))
        ->not->toThrow(InvalidConfigurationException::class);

    Log::shouldHaveReceived('warning')->twice();
});

it('treats an unexpanded env placeholder as missing', function () {
    Log::spy();
    config()->set('mpp.methods.stripe', [
        'verifier' => StripeVerifier::class,
        'secret_key' => '${STRIPE_SECRET_KEY}',
        'network_id' => 'profile_live',
    ]);

    $this->validator->validateMethod('stripe');

    Log::shouldHaveReceived('warning')->once(); // secret_key only; network_id is set
});

it('logs each recommended-config warning only once per process', function () {
    Log::spy();
    config()->set('mpp.methods.stripe', ['verifier' => StripeVerifier::class]);

    $this->validator->validateMethod('stripe');
    $this->validator->validateMethod('stripe');

    Log::shouldHaveReceived('warning')->twice(); // not four times
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

it('rejects co-offering the mppx rail (Tempo) with a native rail', function () {
    config()->set('mpp.methods.tempo.verifier', TempoVerifier::class);

    $spec = new PaymentSpec(
        amount: '0.50', currency: 'USD', grants: 1, scope: 'x',
        method: 'stripe', offeredMethods: ['stripe', 'tempo'],
    );

    expect(fn () => $this->validator->validate($spec))
        ->toThrow(InvalidConfigurationException::class, 'mppx');
});

it('rejects the mppx rail co-offered even when it is the primary method', function () {
    config()->set('mpp.methods.tempo.verifier', TempoVerifier::class);

    $spec = new PaymentSpec(
        amount: '0.01', currency: 'USD', grants: 1, scope: 'x',
        method: 'tempo', offeredMethods: ['tempo', 'stripe'],
    );

    expect(fn () => $this->validator->validate($spec))
        ->toThrow(InvalidConfigurationException::class, 'co-offered');
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
    config()->set('mpp.methods.stripe', ['verifier' => StripeVerifier::class, 'secret_key' => 'sk_test', 'network_id' => 'profile_x']);
    config()->set('mpp.methods.other', ['verifier' => 'App\\Settlement\\OtherVerifier']);

    $spec = new PaymentSpec(
        amount: '0.50', currency: 'USD', grants: 1, scope: 'x',
        method: 'stripe', offeredMethods: ['stripe', 'other'],
    );

    expect(fn () => $this->validator->validate($spec))
        ->not->toThrow(InvalidConfigurationException::class);
});

it('treats a non-Tempo verifier under the tempo name as native (exempt from the dialect guard)', function () {
    // This is what the suite's FakeTempoVerifier relies on to exercise native multi-accept.
    config()->set('mpp.methods.tempo.verifier', FakeTempoVerifier::class);

    $spec = new PaymentSpec(
        amount: '0.50', currency: 'USD', grants: 1, scope: 'x',
        method: 'stripe', offeredMethods: ['stripe', 'tempo'],
    );

    expect(fn () => $this->validator->validate($spec))
        ->not->toThrow(InvalidConfigurationException::class);
});
