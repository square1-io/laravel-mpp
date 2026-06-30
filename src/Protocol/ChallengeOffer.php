<?php

namespace Square1\Mpp\Protocol;

/**
 * One settlement method offered by a Challenge. A Challenge always has a primary
 * method (its own `method`/`networkId`/`paymentMethodTypes`); when more than one
 * method is offered, each additional method is represented by a ChallengeOffer.
 *
 * Only the per-method binding fields live here — method id, the rail's network
 * id, and the accepted payment method types. The shared economic fields (amount,
 * currency, grants, scope, expiresAt, challenge id) come from the Challenge and
 * are bound into every offer's signature, so an offer for one method cannot be
 * lifted onto a challenge for a different price/scope.
 */
final class ChallengeOffer
{
    /**
     * @param  list<string>  $paymentMethodTypes
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $networkId = null,
        public readonly array $paymentMethodTypes = ['card'],
    ) {}
}
