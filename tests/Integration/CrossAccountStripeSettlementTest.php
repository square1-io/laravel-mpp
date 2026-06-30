<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Settlement\StripeVerifier;
use Stripe\StripeClient;

/**
 * Cross-account Stripe settlement — the real MPP topology.
 *
 * A Shared Payment Token is MINTED by one test account (the buyer/payer, whose
 * wallet owns the card) and SETTLED by a DIFFERENT test account (the seller,
 * whose key is configured in mpp.methods.stripe.secret_key). The two roles are
 * separate businesses; the single-account test in StripeSettlementTest collapses
 * them only for convenience. StripeVerifier (settlement) only ever uses the
 * seller key — it never mints.
 *
 * Test-mode granted tokens are not scoped to a seller, so any account can settle
 * one (no network_id needed). In live mode the buyer scopes the token to your
 * profile via network_id, so only you can charge it — but live is US-gated.
 *
 * Needs two sk_test_ keys from two DIFFERENT Stripe accounts. Keep them out of
 * shell history — put them in the gitignored .stripe-test.env and source it:
 *
 *   # .stripe-test.env   (copy from .stripe-test.env.example)
 *   STRIPE_BUYER_SECRET_KEY=sk_test_…   # account A — mints the SPT
 *   STRIPE_SECRET_KEY=sk_test_…         # account B — settles it (your seller acct)
 *
 *   set -a; source .stripe-test.env; set +a
 *   vendor/bin/pest --group=stripe-cross
 */
beforeEach(function () {
    $buyer = (string) (getenv('STRIPE_BUYER_SECRET_KEY') ?: env('STRIPE_BUYER_SECRET_KEY', ''));
    $seller = (string) (getenv('STRIPE_SECRET_KEY') ?: env('STRIPE_SECRET_KEY', ''));

    if (! str_starts_with($buyer, 'sk_test_') || ! str_starts_with($seller, 'sk_test_')) {
        $this->markTestSkipped('Set STRIPE_BUYER_SECRET_KEY and STRIPE_SECRET_KEY (two different sk_test_ accounts) to run the cross-account test.');
    }

    $this->buyerKey = $buyer;
    $this->sellerKey = $seller;
    $this->apiVersion = (string) (getenv('STRIPE_API_VERSION') ?: '2026-05-27.preview');
});

test('an SPT minted by the buyer account settles on a different seller account', function () {
    $buyer = new StripeClient(['api_key' => $this->buyerKey, 'stripe_version' => $this->apiVersion]);
    $seller = new StripeClient(['api_key' => $this->sellerKey, 'stripe_version' => $this->apiVersion]);

    // The whole point is that these are two different accounts — assert it, so a
    // misconfigured pair of keys fails loudly instead of silently self-settling.
    $buyerAcct = $buyer->deserialize($buyer->rawRequest('get', '/v1/account', null, ['stripe_version' => $this->apiVersion])->body)->id;
    $sellerAcct = $seller->deserialize($seller->rawRequest('get', '/v1/account', null, ['stripe_version' => $this->apiVersion])->body)->id;

    expect($buyerAcct)->not->toBe($sellerAcct);

    // Buyer mints the SPT (their wallet, their card). $1.00 clears the card minimum.
    $minted = $buyer->rawRequest('post', '/v1/test_helpers/shared_payment/granted_tokens', [
        'payment_method' => 'pm_card_visa',
        'usage_limits' => ['currency' => 'usd', 'max_amount' => 100, 'expires_at' => time() + 300],
    ], ['stripe_version' => $this->apiVersion]);
    $spt = $buyer->deserialize($minted->body)->id;

    // Seller settles it — the only key the package ever uses.
    $challenge = new Challenge('chal_'.bin2hex(random_bytes(8)), 'stripe', '1.00', 'USD', 1, 'clip', CarbonImmutable::now()->addMinutes(5));
    $result = (new StripeVerifier($this->sellerKey, $this->apiVersion))
        ->verify(new Credential('stripe', $challenge->id, $spt), $challenge);

    expect($result->succeeded)->toBeTrue()
        ->and($result->settlementRef)->toStartWith('pi_')
        ->and($result->amountMinor)->toBe(100);
})->group('stripe-cross');
