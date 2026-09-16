<?php

namespace Square1\Mpp\Payment;

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Support\Money;

/**
 * The resolved payment requirement for a route.
 *
 * The spec states the amount, the currency, the number of accesses that one
 * payment grants, the scope, and the offered settlement methods. The package
 * builds it from middleware arguments or from a #[RequiresPayment] attribute,
 * and passes it to the PaymentGate.
 *
 * `method` is the PRIMARY offered method, which is the first and default one.
 * The package keeps it for ordering and for compatibility with single-method
 * routes. `offeredMethods` is the full ordered set. When that set holds one
 * entry, the challenge is byte-identical to a challenge from before the package
 * supported several rails.
 *
 * The spec is immutable. A price resolver changes one with `with()`, which
 * returns a new instance and validates the overrides. See that method.
 */
final class PaymentSpec
{
    /** The only keys that a price resolver can override. */
    private const OVERRIDABLE = ['amount', 'currency', 'grants', 'scope', 'free'];

    /**
     * @param  ?string  $amount  Null when the route states no price and leaves it to its
     *                           resolvers. The package resolves the amount before the spec
     *                           reaches the gate. A chargeable spec therefore always has an
     *                           amount by then, and only a price resolver can receive a null
     *                           one.
     * @param  list<string>  $offeredMethods  ordered set of offered method names (primary first)
     * @param  list<string>  $preconditions  named precondition checks to run before a challenge is minted or settled
     * @param  list<string>  $pricing  named price resolvers to apply to this route, in order
     * @param  bool  $free  when true the gate serves the route without a charge (only a resolver that returns `free => true` sets it)
     */
    public function __construct(
        public readonly ?string $amount,
        public readonly string $currency,
        public readonly int $grants,
        public readonly string $scope,
        public readonly string $method,
        public readonly array $offeredMethods = [],
        public readonly array $preconditions = [],
        public readonly array $pricing = [],
        public readonly bool $free = false,
    ) {}

    public function isMetered(): bool
    {
        return $this->grants > 1;
    }

    /**
     * Reports whether anything has set a price: the route, a global default, or
     * a resolver.
     *
     * A spec that is still unpriced after its resolvers have run, and that no
     * resolver waived, cannot be charged for. Such a spec never reaches the
     * gate.
     */
    public function isPriced(): bool
    {
        return $this->amount !== null;
    }

    /**
     * Returns a copy with the overrides of a price resolver applied.
     *
     * A resolver can override only `amount`, `currency`, `grants`, `scope` and
     * `free`. The SpecResolver resolves the rail fields, `method` and
     * `offeredMethods`, once, and a price resolver cannot change them. An
     * unrecognised key throws, and the method does not ignore it. A typo
     * therefore cannot serve the wrong price without a message.
     *
     * A resolver must state a free route as `free => true`. The method rejects a
     * zero, negative or non-numeric `amount`. A resolver that computes an empty
     * or incorrect value therefore fails with a message, and does not give the
     * resource away.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        $unknown = array_diff(array_keys($overrides), self::OVERRIDABLE);

        if ($unknown !== []) {
            throw new InvalidConfigurationException(sprintf(
                'A price resolver returned unknown override(s): %s. Only %s may be overridden.',
                implode(', ', $unknown),
                implode(', ', self::OVERRIDABLE),
            ));
        }

        // This is the one rule that covers two keys. `free` and `amount`
        // together are ambiguous. A resolver that computes both has a defect,
        // and to choose one of them would be the kind of silent mistake that
        // this list prevents.
        if (($overrides['free'] ?? false) === true && array_key_exists('amount', $overrides)) {
            throw new InvalidConfigurationException(
                "A price resolver returned both 'free' => true and an 'amount'. Return one or the other: "
                ."'free' => true waives the charge entirely."
            );
        }

        return new self(
            amount: $this->overrideAmount($overrides),
            currency: $this->overrideString($overrides, 'currency', $this->currency, upper: true, hint: "Return a currency code like 'USD', or omit the key."),
            grants: $this->overrideGrants($overrides),
            scope: $this->overrideString($overrides, 'scope', $this->scope, upper: false, hint: 'Return a non-empty scope, or omit the key.'),
            method: $this->method,
            offeredMethods: $this->offeredMethods,
            preconditions: $this->preconditions,
            pricing: $this->pricing,
            free: $this->overrideFree($overrides),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function overrideFree(array $overrides): bool
    {
        if (! array_key_exists('free', $overrides)) {
            return $this->free;
        }

        if (! is_bool($overrides['free'])) {
            throw new InvalidConfigurationException(
                "A price resolver returned a non-boolean 'free'. Use `'free' => true` to waive the charge."
            );
        }

        return $overrides['free'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function overrideAmount(array $overrides): ?string
    {
        if (! array_key_exists('amount', $overrides)) {
            return $this->amount;
        }

        $amount = $overrides['amount'];

        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            throw new InvalidConfigurationException(
                "A price resolver returned a non-numeric 'amount'. Return a decimal string like '2.00'."
            );
        }

        $amount = trim((string) $amount);

        // Money owns the format rule, so the gate and the mint agree on what a
        // well-formed amount is. This class owns the rule that the amount must
        // be positive.
        if (! Money::isValidAmount($amount) || (float) $amount <= 0) {
            throw new InvalidConfigurationException(sprintf(
                "A price resolver returned an invalid 'amount' (%s). It must be a positive number; "
                ."to waive the charge return `'free' => true` instead.",
                $amount === '' ? "''" : $amount,
            ));
        }

        return $amount;
    }

    /**
     * The shared rule for the string overrides.
     *
     * An absent key keeps the current value. A value that is not a string, or
     * that is blank, is a defect in the resolver, and the method throws.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function overrideString(array $overrides, string $key, string $current, bool $upper, string $hint): string
    {
        if (! array_key_exists($key, $overrides)) {
            return $current;
        }

        $value = is_string($overrides[$key]) ? trim($overrides[$key]) : '';

        if ($value === '') {
            throw new InvalidConfigurationException(
                "A price resolver returned an empty '{$key}'. {$hint}"
            );
        }

        return $upper ? strtoupper($value) : $value;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function overrideGrants(array $overrides): int
    {
        if (! array_key_exists('grants', $overrides)) {
            return $this->grants;
        }

        $grants = $overrides['grants'];

        if ((! is_int($grants) && ! (is_string($grants) && ctype_digit($grants))) || (int) $grants < 1) {
            throw new InvalidConfigurationException(
                "A price resolver returned an invalid 'grants'. It must be an integer of 1 or more."
            );
        }

        return (int) $grants;
    }
}
