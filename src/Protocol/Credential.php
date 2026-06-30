<?php

namespace Square1\Mpp\Protocol;

/**
 * A parsed `Authorization: Payment` credential. Either a settlement-proof
 * credential (a rail-neutral proof + the challenge it answers + that challenge's
 * signature) or a session credential (a prepaid balance issued by a prior
 * payment).
 *
 * The settlement proof is rail-neutral: `proof="…"` carries it for any rail
 * (e.g. an on-chain tx reference), while `spt="…"` remains the Stripe-specific
 * alias. `proof()` returns whichever was presented so a Verifier can read the
 * proof without caring which attribute the client used. `spt`/`isSpt()` keep
 * working unchanged so the Stripe rail (and camps' subclass) read the SPT
 * exactly as before.
 */
class Credential
{
    public function __construct(
        public readonly string $method,
        public readonly ?string $challengeId = null,
        public readonly ?string $spt = null,
        public readonly ?string $session = null,
        public readonly ?string $signature = null,
        public readonly ?string $proof = null,
    ) {}

    public function isSpt(): bool
    {
        return $this->spt !== null && $this->spt !== '';
    }

    public function isSession(): bool
    {
        return $this->session !== null && $this->session !== '';
    }

    /**
     * The rail-neutral settlement proof presented by the client. Prefers the
     * explicit `proof` attribute and falls back to `spt` so the Stripe alias
     * keeps working.
     */
    public function proof(): ?string
    {
        return ($this->proof !== null && $this->proof !== '') ? $this->proof : $this->spt;
    }

    /**
     * Whether any settlement proof (rail-neutral `proof` or Stripe `spt`) was
     * presented. The rail-neutral companion to isSpt().
     */
    public function isSettlementProof(): bool
    {
        $proof = $this->proof();

        return $proof !== null && $proof !== '';
    }
}
