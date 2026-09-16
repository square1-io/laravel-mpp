<?php

namespace Square1\Mpp\Settlement;

/**
 * The result of a {@see SettlementChecker} that inspected a settlement proof on
 * an external rail, such as a chain.
 *
 * The outcome states whether the rail confirms a finalised payment. When the
 * rail does confirm one, the outcome also carries the canonical settlement
 * reference, and the settled amount, currency and recipient. The Verifier checks
 * those values against the challenge.
 *
 * A checker NEVER decides whether the payment satisfies the challenge. It
 * reports the facts that it can read from the rail. The Verifier owns the match
 * of amount, recipient and finality against the signed challenge.
 */
final class SettlementOutcome
{
    /**
     * @param  bool  $confirmed  whether the rail reports a finalised settlement for the proof
     * @param  string|null  $settlementRef  the canonical reference for the settlement, such as a transaction hash
     * @param  int|string|null  $amountMinor  the settled amount in minor units, as read from the rail. A string keeps an on-chain amount exact above PHP_INT_MAX.
     * @param  string|null  $currency  the settled currency or asset code, as read from the rail
     * @param  string|null  $recipient  the address or account that the funds settled to, as read from the rail
     * @param  int|null  $confirmations  the confirmations observed for the settlement, when known
     * @param  string|null  $reason  the failure reason, in plain words, when the rail does not confirm
     */
    public function __construct(
        public readonly bool $confirmed,
        public readonly ?string $settlementRef = null,
        public readonly int|string|null $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $recipient = null,
        public readonly ?int $confirmations = null,
        public readonly ?string $reason = null,
    ) {}

    public static function confirmed(string $settlementRef, int|string $amountMinor, string $currency, string $recipient, ?int $confirmations = null): self
    {
        return new self(
            confirmed: true,
            settlementRef: $settlementRef,
            amountMinor: $amountMinor,
            currency: strtoupper($currency),
            recipient: $recipient,
            confirmations: $confirmations,
        );
    }

    public static function unconfirmed(string $reason, ?int $confirmations = null): self
    {
        return new self(confirmed: false, confirmations: $confirmations, reason: $reason);
    }
}
