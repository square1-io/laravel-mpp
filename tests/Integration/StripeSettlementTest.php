<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Settlement\StripeVerifier;
use Stripe\StripeClient;

/**
 * Settles a real Stripe TEST-MODE Shared Payment Token through StripeVerifier.
 * Hits the real Stripe API (creating livemode:false objects), so it is skipped
 * unless a test secret key is present. This exercises the settlement layer
 * directly — no HTTP rig — which is the unit you'd verify against your account.
 *
 *   STRIPE_SECRET_KEY=sk_test_... vendor/bin/pest --group=stripe
 */
beforeEach(function () {
    $key = (string) (getenv('STRIPE_SECRET_KEY') ?: env('STRIPE_SECRET_KEY', ''));

    if ($key === '' || ! str_starts_with($key, 'sk_test_')) {
        $this->markTestSkipped('Set STRIPE_SECRET_KEY (sk_test_...) to run the Stripe settlement test.');
    }

    $this->secretKey = $key;
    $this->apiVersion = (string) (getenv('STRIPE_API_VERSION') ?: '2026-05-27.preview');
});

test('StripeVerifier settles a freshly minted test SPT', function () {
    $client = new StripeClient(['api_key' => $this->secretKey, 'stripe_version' => $this->apiVersion]);

    // $1.00 clears Stripe's per-charge minimum across currencies.
    $minted = $client->rawRequest('post', '/v1/test_helpers/shared_payment/granted_tokens', [
        'payment_method' => 'pm_card_visa',
        'usage_limits' => ['currency' => 'usd', 'max_amount' => 100, 'expires_at' => time() + 300],
    ], ['stripe_version' => $this->apiVersion]);
    $spt = $client->deserialize($minted->body)->id;

    $challenge = new Challenge('chal_'.bin2hex(random_bytes(8)), 'stripe', '1.00', 'USD', 1, 'clip', CarbonImmutable::now()->addMinutes(5));
    $result = (new StripeVerifier($this->secretKey, $this->apiVersion))
        ->verify(new Credential('stripe', $challenge->id, $spt), $challenge);

    expect($result->succeeded)->toBeTrue()
        ->and($result->settlementRef)->toStartWith('pi_')
        ->and($result->amountMinor)->toBe(100);
})->group('stripe');
