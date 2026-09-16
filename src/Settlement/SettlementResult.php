<?php

namespace Square1\Mpp\Settlement;

use Carbon\CarbonImmutable;

/**
 * The result of an attempt to settle a credential against a challenge.
 *
 * `settlementRef` is the canonical reference for the settlement, and it is
 * independent of the rail. It is a Stripe PaymentIntent id, an on-chain
 * transaction hash, or the equivalent for another rail.
 */
class SettlementResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $settlementRef = null,
        // This is in minor units. A string keeps an on-chain amount exact above
        // PHP_INT_MAX. A fiat rail can pass an int.
        public readonly int|string|null $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?CarbonImmutable $settledAt = null,
        public readonly ?string $failureReason = null,
    ) {}

    /**
     * Builds a successful result from a settlement reference that is independent
     * of the rail.
     */
    public static function settled(string $settlementRef, int|string|null $amountMinor = null, ?string $currency = null, ?CarbonImmutable $settledAt = null): self
    {
        return new self(
            succeeded: true,
            settlementRef: $settlementRef,
            amountMinor: $amountMinor,
            currency: $currency === null ? null : strtoupper($currency),
            settledAt: $settledAt ?? CarbonImmutable::now(),
        );
    }

    public static function failure(string $reason): self
    {
        return new self(succeeded: false, failureReason: $reason);
    }
}
