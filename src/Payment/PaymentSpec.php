<?php

namespace Square1\Mpp\Payment;

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Support\Money;

/**
 * The resolved payment requirement for a route: how much, in what currency, how
 * many accesses one payment grants, within what scope, and which settlement
 * methods are offered. Built from middleware arguments or a #[RequiresPayment]
 * attribute and handed to the PaymentGate.
 *
 * `method` is the PRIMARY (first/default) offered method, kept for ordering and
 * single-method back-compat. `offeredMethods` is the full ordered set; when it
 * holds a single entry the resulting challenge is byte-identical to a
 * pre-multi-rail challenge.
 *
 * The spec is immutable. A price resolver adjusts one with `with()`, which
 * returns a new instance and validates the overrides (see that method).
 */
final class PaymentSpec
{
    /** The only keys a price resolver may override. */
    private const OVERRIDABLE = ['amount', 'currency', 'grants', 'scope', 'free'];

    /**
     * @param  ?string  $amount  null when the route states no price and leaves it entirely to
     *                           its resolvers. Resolved before the spec reaches the gate: a
     *                           chargeable spec always has an amount by then, so only a price
     *                           resolver can ever be handed a null one.
     * @param  list<string>  $offeredMethods  ordered set of offered method names (primary first)
     * @param  list<string>  $preconditions  named precondition checks to run before a challenge is minted or settled
     * @param  list<string>  $pricing  named price resolvers to apply to this route, in order
     * @param  bool  $free  when true the gate serves the route without charging (set only by a resolver returning `free => true`)
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
     * Has anything set a price yet — the route itself, a global default, or a
     * resolver? A spec that is still unpriced once its resolvers have run, and
     * was not waived, cannot be charged for and never reaches the gate.
     */
    public function isPriced(): bool
    {
        return $this->amount !== null;
    }

    /**
     * Return a copy with a price resolver's overrides applied.
     *
     * Only `amount`, `currency`, `grants`, `scope` and `free` may be overridden;
     * the rail fields (`method`, `offeredMethods`) are resolved once by the
     * SpecResolver and are not a resolver's to change. An unrecognised key throws
     * rather than being ignored, so a typo can never silently serve the wrong price.
     *
     * A free route must be stated as `free => true`. A zero, negative or
     * non-numeric `amount` is rejected, so a resolver that computes an empty or
     * bad value fails loudly instead of quietly giving the resource away.
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

        // The one rule that spans two keys: `free` and `amount` together is
        // ambiguous — a resolver that computes both has a bug, and guessing which
        // one wins is exactly the kind of silent mistake this list guards against.
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

        // Format is Money's rule (so the gate and mint time agree on what a
        // well-formed amount is); "must be positive" is this spec's own.
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
     * Shared shape for the string overrides: absent leaves the current value, a
     * non-string or blank one is a resolver bug and throws.
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
