<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Settlement\StripeVerifier;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;

function stripeChallenge(string $amount = '5.00', string $currency = 'USD'): Challenge
{
    return new Challenge('chal_test', 'stripe', $amount, $currency, 1, 'report.basic', CarbonImmutable::now()->addMinutes(5));
}

function stripeClientWith(PaymentIntentService $service): StripeClient
{
    $client = new class(['api_key' => 'sk_test_dummy']) extends StripeClient
    {
        public $paymentIntents;
    };
    $client->paymentIntents = $service;

    return $client;
}

afterEach(fn () => Mockery::close());

it('succeeds when the payment intent succeeds and matches', function () {
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_1', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'usd'])
    );
    $verifier = new StripeVerifier('sk_test_x', client: stripeClientWith($service));

    $result = $verifier->verify(new Credential('stripe', 'chal_test', 'spt_x'), stripeChallenge());

    expect($result->succeeded)->toBeTrue()
        ->and($result->settlementRef)->toBe('pi_1')
        ->and($result->amountMinor)->toBe(500);
});

it('fails when the payment intent is not succeeded', function () {
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_2', 'status' => 'requires_action', 'amount' => 500, 'currency' => 'usd'])
    );
    $result = (new StripeVerifier('sk_test_x', client: stripeClientWith($service)))
        ->verify(new Credential('stripe', 'chal_test', 'spt_x'), stripeChallenge());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('did not succeed');
});

it('fails when the settled amount does not match', function () {
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_3', 'status' => 'succeeded', 'amount' => 999, 'currency' => 'usd'])
    );
    $result = (new StripeVerifier('sk_test_x', client: stripeClientWith($service)))
        ->verify(new Credential('stripe', 'chal_test', 'spt_x'), stripeChallenge());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('did not match');
});

it('fails gracefully on a Stripe exception', function () {
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->andThrow(new RuntimeException('boom'));
    $result = (new StripeVerifier('sk_test_x', client: stripeClientWith($service)))
        ->verify(new Credential('stripe', 'chal_test', 'spt_x'), stripeChallenge());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('Stripe settlement error');
});

it('attaches a customer from the settlement context', function () {
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->with(
        Mockery::on(fn ($params) => ($params['customer'] ?? null) === 'cus_x'),
        Mockery::any()
    )->andReturn(PaymentIntent::constructFrom(['id' => 'pi_4', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'usd']));

    $result = (new StripeVerifier('sk_test_x', client: stripeClientWith($service)))
        ->verify(new Credential('stripe', 'chal_test', 'spt_x'), stripeChallenge(), ['customer' => 'cus_x']);

    expect($result->succeeded)->toBeTrue();
});

it('fails without an SPT', function () {
    $result = (new StripeVerifier('sk_test_x'))->verify(new Credential('stripe', session: 'sess_x'), stripeChallenge());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('No shared payment token');
});
