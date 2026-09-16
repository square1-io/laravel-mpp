<?php

namespace Square1\Mpp\Protocol\Tempo;

use Carbon\CarbonImmutable;

/**
 * The part of an issued challenge that the tempo rail uses, as TempoVerifier
 * reads it.
 *
 * The `request` of a tempo challenge carries the amount in the minor units of
 * the token, the token address as `currency`, the recipient, and the chainId.
 * The id of the challenge is an HMAC over the challenge fields.
 *
 * This state lets the paid retry prove two things, and the server does not have
 * to trust the client for either:
 *
 *   - the echoed challenge id is an id that this server issued, and the
 *     challenge has not expired. The server looks the id up in the store and
 *     checks the expiry.
 *   - the signed transaction pays exactly this amount of this token to this
 *     recipient, and carries the exact memo that this challenge advertised.
 *
 * The challenge binding therefore stays significant. A transaction that a client
 * minted for one challenge cannot settle another challenge. A challenge that has
 * expired, or that this server did not issue, fails closed.
 */
final class TempoChallengeState
{
    public function __construct(
        public readonly string $id,
        public readonly string $realm,
        public readonly string $amount,      // the minor units of the token, as a decimal string
        public readonly string $token,       // the contract address of the token (the "currency" of mppx)
        public readonly string $recipient,   // the address that the funds must settle to
        public readonly int $chainId,
        public readonly CarbonImmutable $expiresAt,
        public readonly int $grants = 1,
        public readonly string $scope = 'default',
        public readonly string $intent = 'charge',
        public readonly string $memo = '',   // the advertised bytes32 memo that the paid transfer must carry
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
     * Returns the `request` object of mppx, in the field order that the
     * reference implementation canonicalises to, which is alphabetical: amount,
     * currency, methodDetails, recipient.
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
