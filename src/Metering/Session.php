<?php

namespace Square1\Mpp\Metering;

use Carbon\CarbonImmutable;

/**
 * A prepaid metering session: an opaque credit balance, decremented per request,
 * spendable only within its scope until it expires or hits zero.
 */
class Session
{
    public function __construct(
        public readonly string $id,
        public readonly string $scope,
        public readonly int $remaining,
        public readonly CarbonImmutable $grantedAt,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?string $settlementRef = null,
        public readonly ?string $payerRef = null,
    ) {}

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($this->expiresAt);
    }

    public function isExhausted(): bool
    {
        return $this->remaining <= 0;
    }

    public function withRemaining(int $remaining): self
    {
        return new self(
            id: $this->id,
            scope: $this->scope,
            remaining: $remaining,
            grantedAt: $this->grantedAt,
            expiresAt: $this->expiresAt,
            settlementRef: $this->settlementRef,
            payerRef: $this->payerRef,
        );
    }
}
