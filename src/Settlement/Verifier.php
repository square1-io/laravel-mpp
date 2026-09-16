<?php

namespace Square1\Mpp\Settlement;

use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;

/**
 * The abstraction for a settlement rail.
 *
 * The protocol layer never trusts a claim by a client that it paid. A Verifier
 * proves the settlement against the rail itself.
 */
interface Verifier
{
    /**
     * @param  array<string, mixed>  $context  Optional settlement context. The Stripe rail
     *                                         reads `customer`, which is a customer id on the
     *                                         seller account to attach to the PaymentIntent.
     */
    public function verify(Credential $credential, Challenge $challenge, array $context = []): SettlementResult;
}
