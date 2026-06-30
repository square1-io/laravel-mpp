<?php

namespace Square1\Mpp\Payment;

use Square1\Mpp\Protocol\ChallengeOffer;

/**
 * The resolved payment requirement for a route: how much, in what currency, how
 * many accesses one payment grants, within what scope, and which settlement
 * methods are offered. Built from middleware arguments or a #[RequiresPayment]
 * attribute and handed to the PaymentGate.
 *
 * `method` is the PRIMARY (first/default) offered method, kept for ordering and
 * single-method back-compat. `offeredMethods` is the full ordered set; when it
 * holds a single entry the resulting challenge is byte-identical to a
 * pre-multi-rail challenge.
 */
final class PaymentSpec
{
    /**
     * @param  list<string>  $paymentMethodTypes  the PRIMARY method's payment method types
     * @param  list<string>  $offeredMethods  ordered set of offered method names (primary first)
     * @param  list<string>  $preconditions  named precondition checks to run before a challenge is minted or settled
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly int $grants,
        public readonly string $scope,
        public readonly string $method,
        public readonly ?string $networkId = null,
        public readonly array $paymentMethodTypes = ['card'],
        public readonly array $offeredMethods = [],
        public readonly array $preconditions = [],
    ) {}

    public function isMetered(): bool
    {
        return $this->grants > 1;
    }

    /**
     * Shape expected by ChallengeFactory::mint(). The primary method's binding
     * fields stay at the top level (so a single-method spec mints an unchanged
     * challenge); any additional offered methods become `offers[]`.
     *
     * @return array<string, mixed>
     */
    public function toChallengeSpec(): array
    {
        $offers = [];
        foreach ($this->offeredMethods as $method) {
            if ($method === $this->method) {
                continue; // the primary is represented at the top level
            }

            $config = config("mpp.methods.{$method}", []);
            $offers[] = new ChallengeOffer(
                method: $method,
                networkId: $config['network_id'] ?? null,
                paymentMethodTypes: $config['payment_method_types'] ?? ['card'],
            );
        }

        return [
            'method' => $this->method,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'grants' => $this->grants,
            'scope' => $this->scope,
            'networkId' => $this->networkId,
            'paymentMethodTypes' => $this->paymentMethodTypes,
            'offers' => $offers,
        ];
    }
}
