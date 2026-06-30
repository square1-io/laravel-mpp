<?php

namespace Square1\Mpp\Protocol\Tempo;

use Carbon\CarbonImmutable;

/**
 * The persisted state of an issued mppx-dialect (tempo) challenge.
 *
 * The tempo wire dialect differs from the package's native challenge: its
 * challenge carries a `realm`, an mppx `request` blob (amount in token minor
 * units, the token/currency address, the recipient and chainId), and an id that
 * is an HMAC over those fields. We persist this state when we issue the 402 so
 * that on the paid retry we can prove — without trusting the client — that:
 *
 *   - the echoed challenge id is one we issued and is unexpired (store lookup +
 *     expiry), and
 *   - the signed transaction pays exactly this amount of this token to this
 *     recipient, with a memo bound to THIS challenge id under THIS realm.
 *
 * This keeps the challenge binding load-bearing: a transaction minted for one
 * challenge cannot settle another, and an expired/unknown challenge fails closed.
 */
final class TempoChallengeState
{
    public function __construct(
        public readonly string $id,
        public readonly string $realm,
        public readonly string $amount,      // token minor units, decimal string
        public readonly string $token,       // token contract address (the mppx "currency")
        public readonly string $recipient,   // address funds must settle to
        public readonly int $chainId,
        public readonly CarbonImmutable $expiresAt,
        public readonly int $grants = 1,
        public readonly string $scope = 'default',
        public readonly string $intent = 'charge',
    ) {}

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThan($this->expiresAt);
    }

    public function isMetered(): bool
    {
        return $this->grants > 1;
    }

    /**
     * The mppx `request` object, in the exact field order the reference
     * canonicalises (alphabetical): amount, currency, methodDetails, recipient.
     *
     * @return array<string, mixed>
     */
    public function toRequestArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->token,
            'methodDetails' => ['chainId' => $this->chainId],
            'recipient' => $this->recipient,
        ];
    }
}
