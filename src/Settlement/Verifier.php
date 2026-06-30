<?php

namespace Square1\Mpp\Settlement;

use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;

/**
 * Settlement rail abstraction. The protocol layer never trusts a client's claim
 * that it paid — a Verifier proves settlement against the underlying rail.
 */
interface Verifier
{
    /**
     * @param  array<string, mixed>  $context  optional settlement context. The Stripe rail
     *                                         honours `customer` (a seller-account customer
     *                                         id to attach to the PaymentIntent).
     */
    public function verify(Credential $credential, Challenge $challenge, array $context = []): SettlementResult;
}
