<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Settlement\StripeVerifier;
use Square1\Mpp\Support\Money;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;

function stripeChallenge(string $amount = '5.00', string $currency = 'USD'): Challenge
{
    return new Challenge(
        id: 'chal_test',
        realm: 'api.test',
        method: 'stripe',
        intent: 'charge',
        request: [
            'amount' => (string) Money::toMinorUnits($amount, $currency),
            'currency' => strtolower($currency),
        ],
        expiresAt: CarbonImmutable::now()->addMinutes(5),
        opaque: ['scope' => 'report.basic'],
    );
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

    $result = $verifier->verify(new Credential(challenge: ['id' => 'chal_test'], payload: ['spt' => 'spt_x']), stripeChallenge());

    expect($result->succeeded)->toBeTrue()
        ->and($result->settlementRef)->toBe('pi_1')
        ->and($result->amountMinor)->toBe(500);
});

it('fails when the payment intent is not succeeded, without echoing its status', function () {
    Log::spy();
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_2', 'status' => 'requires_action', 'amount' => 500, 'currency' => 'usd'])
    );
    $result = (new StripeVerifier('sk_test_x', client: stripeClientWith($service)))
        ->verify(new Credential(challenge: ['id' => 'chal_test'], payload: ['spt' => 'spt_x']), stripeChallenge());

    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toBe('Stripe settlement failed.')
        ->and($result->failureReason)->not->toContain('requires_action');

    // The operator still gets the status the client is not told.
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []) => ($context['status'] ?? null) === 'requires_action'
    )->once();
});

it('fails when the settled amount does not match', function () {
    Log::spy();
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_3', 'status' => 'succeeded', 'amount' => 999, 'currency' => 'usd'])
    );
    $result = (new StripeVerifier('sk_test_x', client: stripeClientWith($service)))
        ->verify(new Credential(challenge: ['id' => 'chal_test'], payload: ['spt' => 'spt_x']), stripeChallenge());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('did not match');
});

it('fails gracefully on a Stripe exception without leaking its message', function () {
    Log::spy();
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->andThrow(new RuntimeException('card_declined for account acct_secret'));
    $result = (new StripeVerifier('sk_test_x', client: stripeClientWith($service)))
        ->verify(new Credential(challenge: ['id' => 'chal_test'], payload: ['spt' => 'spt_x']), stripeChallenge());

    // The reason reaches the client in the 402, so it is stable and says nothing
    // Stripe told us.
    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toBe('Stripe settlement failed.')
        ->and($result->failureReason)->not->toContain('acct_secret');

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context = []) => str_contains($message, RuntimeException::class)
            && str_contains($message, 'acct_secret')
            && ($context['challenge_id'] ?? null) === 'chal_test'
    )->once();
});

it('attaches a customer from the settlement context', function () {
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('create')->once()->with(
        Mockery::on(fn ($params) => ($params['customer'] ?? null) === 'cus_x'),
        Mockery::any()
    )->andReturn(PaymentIntent::constructFrom(['id' => 'pi_4', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'usd']));

    $result = (new StripeVerifier('sk_test_x', client: stripeClientWith($service)))
        ->verify(new Credential(challenge: ['id' => 'chal_test'], payload: ['spt' => 'spt_x']), stripeChallenge(), ['customer' => 'cus_x']);

    expect($result->succeeded)->toBeTrue();
});

it('fails without an SPT', function () {
    $result = (new StripeVerifier('sk_test_x'))->verify(new Credential(session: 'sess_x'), stripeChallenge());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('No shared payment token');
});
