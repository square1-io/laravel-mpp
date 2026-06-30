<?php

namespace Square1\Mpp\Settlement;

use Carbon\CarbonImmutable;

/**
 * The outcome of attempting to settle a credential against a challenge.
 *
 * `settlementRef` is the rail-neutral canonical reference for the settlement
 * (a Stripe PaymentIntent id, an on-chain tx hash, etc.).
 */
class SettlementResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $settlementRef = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?CarbonImmutable $settledAt = null,
        public readonly ?string $failureReason = null,
    ) {}

    /**
     * Construct a successful result from a rail-neutral settlement reference.
     */
    public static function settled(string $settlementRef, int $amountMinor, string $currency, ?CarbonImmutable $settledAt = null): self
    {
        return new self(
            succeeded: true,
            settlementRef: $settlementRef,
            amountMinor: $amountMinor,
            currency: strtoupper($currency),
            settledAt: $settledAt ?? CarbonImmutable::now(),
        );
    }

    public static function failure(string $reason): self
    {
        return new self(succeeded: false, failureReason: $reason);
    }
}
