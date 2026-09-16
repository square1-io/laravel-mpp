<?php

namespace Square1\Mpp\Settlement;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Stripe\StripeClient;
use Throwable;

/**
 * Settles an SPT credential. It creates a Stripe PaymentIntent and confirms it.
 *
 * The class presents the granted token through
 * `payment_method_data[shared_payment_granted_token]`, with `confirm=true`. It
 * trusts the settlement only when the PaymentIntent has `status === 'succeeded'`
 * AND its amount and currency match the signed challenge. It uses the challenge
 * id as the Stripe idempotency key, so a settlement that the server retries can
 * never charge twice.
 *
 * The class is verified against the SPT preview API, Stripe-Version
 * 2026-05-27.preview.
 *
 * A failure reason travels back to the client in the 402 response. The class
 * therefore logs everything that Stripe returns for the operator, which includes
 * exception messages and PaymentIntent statuses, and replaces it on the wire
 * with {@see self::PUBLIC_FAILURE}. Raw gateway detail can name an internal
 * account, a key, or the internals of a decline. A reason that changes with that
 * detail is also an oracle, which lets a caller probe the Stripe account of the
 * seller.
 */
class StripeVerifier implements Verifier
{
    /** The only reason that a settlement attempt reports to the client. */
    private const PUBLIC_FAILURE = 'Stripe settlement failed.';

    public function __construct(
        private readonly string $secretKey,
        private readonly string $apiVersion = '2026-05-27.preview',
        private readonly ?string $networkId = null,
        private ?StripeClient $client = null,
    ) {}

    public function verify(Credential $credential, Challenge $challenge, array $context = []): SettlementResult
    {
        if ($credential->spt() === null) {
            return SettlementResult::failure('No shared payment token presented.');
        }

        if ($this->secretKey === '' || str_starts_with($this->secretKey, '${')) {
            return SettlementResult::failure('Stripe secret key is not configured (set STRIPE_SECRET_KEY).');
        }

        $expectedMinor = (int) $challenge->amount();

        $metadata = ['mpp_challenge_id' => $challenge->id, 'mpp_scope' => $challenge->scope()];
        if ($this->networkId !== null && $this->networkId !== '') {
            $metadata['mpp_network_id'] = $this->networkId;
        }

        $params = [
            'amount' => $expectedMinor,
            'currency' => $challenge->currency(),
            'payment_method_data' => [
                'shared_payment_granted_token' => $credential->spt(),
            ],
            'confirm' => true,
            'metadata' => $metadata,
        ];

        // Attach a Customer from the seller account, so that the seller can
        // track each payer. This happens only when the application resolved a
        // customer for this request.
        if (! empty($context['customer'])) {
            $params['customer'] = $context['customer'];
        }

        try {
            $paymentIntent = $this->client()->paymentIntents->create($params, [
                'idempotency_key' => $challenge->id,
            ]);
        } catch (Throwable $e) {
            Log::error('[mpp] Stripe settlement raised '.$e::class.': '.$e->getMessage(), [
                'challenge_id' => $challenge->id,
                'scope' => $challenge->scope(),
                'exception' => $e,
            ]);

            return SettlementResult::failure(self::PUBLIC_FAILURE);
        }

        if (($paymentIntent->status ?? null) !== 'succeeded') {
            Log::warning('[mpp] Stripe PaymentIntent did not succeed.', [
                'challenge_id' => $challenge->id,
                'payment_intent' => $paymentIntent->id ?? null,
                'status' => $paymentIntent->status ?? 'unknown',
            ]);

            return SettlementResult::failure(self::PUBLIC_FAILURE);
        }

        // Serve the resource only when the settled money matches the money that
        // the challenge asked for.
        if ((int) $paymentIntent->amount !== $expectedMinor
            || strtolower((string) $paymentIntent->currency) !== $challenge->currency()) {
            Log::error('[mpp] Stripe settled an amount or currency the challenge did not ask for.', [
                'challenge_id' => $challenge->id,
                'payment_intent' => $paymentIntent->id ?? null,
                'expected' => $expectedMinor.' '.$challenge->currency(),
                'settled' => $paymentIntent->amount.' '.strtolower((string) $paymentIntent->currency),
            ]);

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
