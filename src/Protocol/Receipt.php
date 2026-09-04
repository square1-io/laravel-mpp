<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;

/**
 * A settlement receipt, rendered into the `Payment-Receipt` response header.
 *
 * The receipt is rail-neutral: it carries the settling `method` and a canonical
 * `ref` (the rail's settlement reference — a Stripe PaymentIntent id, an on-chain
 * tx hash, etc.), for every method alike.
 *
 * The header is exactly the spec's receipt, {status, method, timestamp,
 * reference}, with no package additions. In particular it carries no amount or
 * currency. Neither the core draft nor the stripe / tempo charge drafts define
 * them on a receipt, and the payer already holds the exact terms in the
 * challenge they echoed. Rendering them here also forced a units choice
 * (decimal for ISO currencies, base units for tokens) that the rest of the wire
 * format never makes, so the same header disagreed with itself across rails.
 * The settlement `reference` is the pointer to the rail's own record of what
 * was charged. `challengeId` is kept on the object for the server's own use
 * (logging, correlation) but is not emitted.
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
        // Spec receipt: base64url(JCS JSON), status always "success" (receipts
        // are only issued on successful settlement), `reference` carries the
        // rail's settlement id. Nothing else: the core draft reserves extra
        // receipt fields for method specifications, not servers.
        return Base64Url::encode(Jcs::encode([
            'status' => 'success',
            'method' => $this->method,
            'timestamp' => $this->settledAt->toIso8601ZuluString(),
            'reference' => $this->settlementRef,
        ]));
    }
}
