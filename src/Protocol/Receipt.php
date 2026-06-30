<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Support\Money;

/**
 * A settlement receipt, rendered into the `Payment-Receipt` response header.
 *
 * The receipt is rail-neutral: it carries the settling `method` and a canonical
 * `ref` (the rail's settlement reference — a Stripe PaymentIntent id, an on-chain
 * tx hash, etc.), for every method alike.
 */
class Receipt
{
    public function __construct(
        public readonly string $id,
        public readonly string $challengeId,
        public readonly string $method,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $settlementRef,
        public readonly CarbonImmutable $settledAt,
    ) {}

    public static function fromSettlement(Challenge $challenge, SettlementResult $result, ?string $method = null): self
    {
        return new self(
            id: 'rcpt_'.Str::ulid(),
            challengeId: $challenge->id,
            method: $method ?? $challenge->method,
            amount: Money::fromMinorUnits($result->amountMinor ?? 0, $result->currency ?? $challenge->currency),
            currency: $result->currency ?? $challenge->currency,
            settlementRef: (string) $result->settlementRef,
            settledAt: $result->settledAt ?? CarbonImmutable::now(),
        );
    }

    public function header(): string
    {
        $parts = [
            'id' => $this->id,
            'challengeId' => $this->challengeId,
            'method' => $this->method,
            'amount' => $this->amount,
            'currency' => $this->currency,
            // Rail-neutral settlement reference, present for every method.
            'ref' => $this->settlementRef,
            'settledAt' => $this->settledAt->toIso8601ZuluString(),
        ];

        return implode(', ', array_map(
            fn ($key, $value) => sprintf('%s="%s"', $key, $value),
            array_keys($parts),
            array_values($parts),
        ));
    }
}
