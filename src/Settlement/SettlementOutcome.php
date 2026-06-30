<?php

namespace Square1\Mpp\Settlement;

/**
 * The result of a {@see SettlementChecker} inspecting an on-chain (or other
 * external-rail) settlement proof. It reports whether the rail confirms a
 * finalised payment and, if so, the canonical settlement reference plus the
 * settled amount/currency/recipient the Verifier checks against the challenge.
 *
 * A checker NEVER decides on its own whether the payment satisfies the
 * challenge — it only reports the facts it can read from the rail. The Verifier
 * owns the amount/recipient/finality matching against the signed challenge.
 */
final class SettlementOutcome
{
    /**
     * @param  bool  $confirmed  whether the rail reports a finalised settlement for the proof
     * @param  string|null  $settlementRef  canonical reference for the settlement (e.g. tx hash)
     * @param  int|null  $amountMinor  settled amount in minor units, as read from the rail
     * @param  string|null  $currency  settled currency / asset code, as read from the rail
     * @param  string|null  $recipient  the address/account funds settled to, as read from the rail
     * @param  int|null  $confirmations  confirmations observed for the settlement, if known
     * @param  string|null  $reason  human-readable failure reason when not confirmed
     */
    public function __construct(
        public readonly bool $confirmed,
        public readonly ?string $settlementRef = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $recipient = null,
        public readonly ?int $confirmations = null,
        public readonly ?string $reason = null,
    ) {}

    public static function confirmed(string $settlementRef, int $amountMinor, string $currency, string $recipient, ?int $confirmations = null): self
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
