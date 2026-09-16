<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;

/**
 * A settlement receipt, which the package renders into the `Payment-Receipt`
 * response header.
 *
 * The receipt is independent of the rail. It carries the `method` that settled
 * the payment and a canonical `ref`, which is the settlement reference of the
 * rail. That reference is a Stripe PaymentIntent id, an on-chain transaction
 * hash, or the equivalent for another rail. Every method uses the same two
 * fields.
 *
 * The header is exactly the receipt of the spec, {status, method, timestamp,
 * reference}. The package adds nothing. In particular, the header carries no
 * amount and no currency. The core draft does not define them on a receipt, and
 * neither do the stripe and tempo charge drafts. The payer also already holds
 * the exact terms, in the challenge that it echoed.
 *
 * To render an amount here would force a choice of units, which would be decimal
 * for an ISO currency and base units for a token. The rest of the wire format
 * never makes that choice, so the same header stated different units on
 * different rails. The settlement `reference` points to the record that the rail
 * itself holds of the amount charged.
 *
 * The object keeps `challengeId` for the server, for logging and correlation.
 * The package does not emit it.
 */
class Receipt
{
    public function __construct(
        public readonly string $id,
        public readonly string $challengeId,
        public readonly string $method,
        public readonly string $settlementRef,
        public readonly CarbonImmutable $settledAt,
    ) {}

    public static function fromSettlement(Challenge $challenge, SettlementResult $result, ?string $method = null): self
    {
        return new self(
            id: 'rcpt_'.Str::ulid(),
            challengeId: $challenge->id,
            method: $method ?? $challenge->method,
            settlementRef: (string) $result->settlementRef,
            settledAt: $result->settledAt ?? CarbonImmutable::now(),
        );
    }

    public function header(): string
    {
        // This is the receipt of the spec: base64url(JCS JSON). The status is
        // always "success", because the server issues a receipt only after a
        // successful settlement. `reference` carries the settlement id of the
        // rail. The receipt carries nothing else. The core draft reserves any
        // further receipt field for a method specification, and not for a
        // server.
        return Base64Url::encode(Jcs::encode([
            'status' => 'success',
            'method' => $this->method,
            'timestamp' => $this->settledAt->toIso8601ZuluString(),
            'reference' => $this->settlementRef,
        ]));
    }
}
