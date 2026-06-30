<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;

/**
 * An immutable payment challenge. For a metered endpoint `amount` is the bundle
 * price and `grants` is how many accesses that one payment buys.
 */
class Challenge
{
    /**
     * @param  list<string>  $paymentMethodTypes
     * @param  list<ChallengeOffer>  $offers  additional settlement methods offered
     *                                        alongside the primary `method`. Empty
     *                                        for a single-method challenge, which
     *                                        keeps the wire shape identical to a
     *                                        pre-multi-rail challenge.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $method,
        public readonly string $amount,
        public readonly string $currency,
        public readonly int $grants,
        public readonly string $scope,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?string $networkId = null,
        public readonly array $paymentMethodTypes = ['card'],
        public readonly string $intent = 'charge',
        public readonly array $offers = [],
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
     * The primary method represented as a ChallengeOffer, for uniform iteration.
     */
    public function primaryOffer(): ChallengeOffer
    {
        return new ChallengeOffer($this->method, $this->networkId, $this->paymentMethodTypes);
    }

    /**
     * Every offered method, primary first, as ChallengeOffer value objects.
     *
     * @return list<ChallengeOffer>
     */
    public function allOffers(): array
    {
        return [$this->primaryOffer(), ...array_values($this->offers)];
    }

    /**
     * The ChallengeOffer for a given method, or null if that method is not
     * offered by this challenge.
     */
    public function offerFor(string $method): ?ChallengeOffer
    {
        foreach ($this->allOffers() as $offer) {
            if ($offer->method === $method) {
                return $offer;
            }
        }

        return null;
    }
}
