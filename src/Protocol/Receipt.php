<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;
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
        $currency = $result->currency ?? $challenge->currency();

        // ISO currencies render as decimal via Money; token-address currencies
        // (tempo) pass their base units through untouched.
        // ISO minor units are bounded fiat, so an int is safe; a token amount is
        // kept as its exact decimal string (it can exceed PHP_INT_MAX).
        $amount = preg_match('/^[A-Za-z]{3}$/', $currency)
            ? Money::fromMinorUnits((int) ($result->amountMinor ?? 0), $currency)
            : (string) ($result->amountMinor ?? $challenge->amount());

        return new self(
            id: 'rcpt_'.Str::ulid(),
            challengeId: $challenge->id,
            method: $method ?? $challenge->method,
            amount: $amount,
            currency: $currency,
            settlementRef: (string) $result->settlementRef,
            settledAt: $result->settledAt ?? CarbonImmutable::now(),
        );
    }

    public function header(): string
    {
        // Spec receipt: base64url(JCS JSON), status always "success" (receipts
        // are only issued on successful settlement), `reference` carries the
        // rail's settlement id. Package extras (challengeId, amount) are
        // additional fields, which method specs explicitly permit.
        return Base64Url::encode(Jcs::encode([
            'status' => 'success',
            'method' => $this->method,
            'timestamp' => $this->settledAt->toIso8601ZuluString(),
            'reference' => $this->settlementRef,
            'challengeId' => $this->challengeId,
            'amount' => $this->amount,
            'currency' => $this->currency,
        ]));
    }
}
