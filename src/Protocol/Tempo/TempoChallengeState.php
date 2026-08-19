<?php

namespace Square1\Mpp\Protocol\Tempo;

use Carbon\CarbonImmutable;

/**
 * The tempo-relevant view of an issued challenge, as TempoVerifier consumes it.
 *
 * A tempo challenge's `request` carries the amount in token minor units, the
 * token address as `currency`, the recipient and the chainId, under an id that
 * is an HMAC over the challenge fields. This state lets the paid retry prove —
 * without trusting the client — that:
 *
 *   - the echoed challenge id is one we issued and is unexpired (store lookup +
 *     expiry), and
 *   - the signed transaction pays exactly this amount of this token to this
 *     recipient, carrying the exact memo this challenge advertised.
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
        public readonly string $memo = '',   // advertised bytes32 memo the paid transfer must carry
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
        $methodDetails = ['chainId' => $this->chainId];

        if ($this->memo !== '') {
            $methodDetails['memo'] = $this->memo;
        }

        return [
            'amount' => $this->amount,
            'currency' => $this->token,
            'methodDetails' => $methodDetails,
            'recipient' => $this->recipient,
        ];
    }
}
