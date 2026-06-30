<?php

namespace Square1\Mpp\Settlement;

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Support\Money;
use Stripe\StripeClient;
use Throwable;

/**
 * Settles an SPT credential by creating and confirming a Stripe PaymentIntent.
 *
 * The granted token is presented via
 * `payment_method_data[shared_payment_granted_token]` with `confirm=true`.
 * Settlement is only trusted when the resulting PaymentIntent has
 * `status === 'succeeded'` AND its amount/currency match the signed challenge.
 * The challenge id is used as the Stripe idempotency key so a retried settlement
 * can never double-charge.
 *
 * Verified against the SPT preview API (Stripe-Version 2026-05-27.preview).
 */
class StripeVerifier implements Verifier
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $apiVersion = '2026-05-27.preview',
        private readonly ?string $networkId = null,
        private ?StripeClient $client = null,
    ) {}

    public function verify(Credential $credential, Challenge $challenge, array $context = []): SettlementResult
    {
        if (! $credential->isSpt()) {
            return SettlementResult::failure('No shared payment token presented.');
        }

        if ($this->secretKey === '' || str_starts_with($this->secretKey, '${')) {
            return SettlementResult::failure('Stripe secret key is not configured (set STRIPE_SECRET_KEY).');
        }

        $expectedMinor = Money::toMinorUnits($challenge->amount, $challenge->currency);

        $metadata = ['mpp_challenge_id' => $challenge->id, 'mpp_scope' => $challenge->scope];
        if ($this->networkId !== null && $this->networkId !== '') {
            $metadata['mpp_network_id'] = $this->networkId;
        }

        $params = [
            'amount' => $expectedMinor,
            'currency' => strtolower($challenge->currency),
            'payment_method_data' => [
                'shared_payment_granted_token' => $credential->spt,
            ],
            'confirm' => true,
            'metadata' => $metadata,
        ];

        // Attach a seller-account Customer for per-payer trackability, if the
        // application resolved one for this request.
        if (! empty($context['customer'])) {
            $params['customer'] = $context['customer'];
        }

        try {
            $paymentIntent = $this->client()->paymentIntents->create($params, [
                'idempotency_key' => $challenge->id,
            ]);
        } catch (Throwable $e) {
            return SettlementResult::failure('Stripe settlement error: '.$e->getMessage());
        }

        if (($paymentIntent->status ?? null) !== 'succeeded') {
            return SettlementResult::failure(
                'PaymentIntent did not succeed (status: '.($paymentIntent->status ?? 'unknown').').'
            );
        }

        // Never serve unless the settled money matches what we challenged for.
        if ((int) $paymentIntent->amount !== $expectedMinor
            || strtolower((string) $paymentIntent->currency) !== strtolower($challenge->currency)) {
            return SettlementResult::failure('Settled amount or currency did not match the challenge.');
        }

        return SettlementResult::settled(
            settlementRef: $paymentIntent->id,
            amountMinor: (int) $paymentIntent->amount,
            currency: strtoupper((string) $paymentIntent->currency),
            settledAt: CarbonImmutable::now(),
        );
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient([
            'api_key' => $this->secretKey,
            'stripe_version' => $this->apiVersion,
        ]);
    }
}
