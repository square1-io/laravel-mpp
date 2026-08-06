<?php

namespace Square1\Mpp\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Applies a route's named price resolvers to its resolved PaymentSpec, so the
 * price can depend on the request — a plan tier, a region, the size of the thing
 * being asked for — instead of being fixed in the route definition.
 *
 * Each name resolves from `mpp.pricing.resolvers` to a [Class::class, 'method']
 * pair (container-resolved, so config:cache-safe) called with the Request and
 * the spec as it stands. It returns an array of overrides (`amount`, `currency`,
 * `grants`, `scope`, or `free => true`) or null to leave the price alone. Global
 * resolvers run first, then the route's own, in declared order and
 * de-duplicated; each sees the result of the ones before it. An unknown name
 * throws, so a typo can never silently fall back to the static price.
 *
 * The price is only advisory until it is minted: the amount a buyer is charged
 * is the one bound into the signed challenge, and settlement verifies against
 * that challenge, never against a freshly-resolved spec. A resolver that returns
 * a different answer between the 402 and the paid retry therefore cannot change
 * what the buyer was quoted.
 */
class PriceResolver
{
    /** @var array<string, bool> metered scopes already warned about this process. */
    private array $warned = [];

    public function apply(Request $request, PaymentSpec $spec): PaymentSpec
    {
        $resolvers = (array) config('mpp.pricing.resolvers', []);

        $names = array_values(array_unique(array_merge(
            (array) config('mpp.pricing.global', []),
            $spec->pricing,
        )));

        if ($names === []) {
            return $spec;
        }

        $original = $spec;

        foreach ($names as $name) {
            $resolver = $resolvers[$name] ?? null;

            if (! is_array($resolver) || count($resolver) !== 2 || ! is_string($resolver[0])) {
                throw new InvalidConfigurationException(
                    "Unknown price resolver '{$name}'. Define it under mpp.pricing.resolvers "
                    ."as a [Class::class, 'method'] pair."
                );
            }

            [$class, $method] = $resolver;

            $overrides = app($class)->{$method}($request, $spec);

            if ($overrides === null || $overrides === []) {
                continue;
            }

            if (! is_array($overrides)) {
                throw new InvalidConfigurationException(
                    "The price resolver '{$name}' must return an array of overrides or null, ".get_debug_type($overrides).' given.'
                );
            }

            $spec = $spec->with($overrides);
        }

        $this->warnOnUnscopedMeteredPrice($original, $spec);

        return $spec;
    }

    /**
     * Sessions are scope-bound, not payer-bound: `consume()` checks only that the
     * session was issued for this scope. So on a metered route whose price varies
     * by request, a session bought at the cheap tier is spendable by any bearer
     * of its id — including one who should have paid more. Varying the `scope`
     * alongside the price keeps the tiers in separate credit pools.
     *
     * Warned once per scope per process rather than enforced: sharing a scope
     * across tiers is a legitimate (if unusual) choice, and this is not a
     * misconfiguration that makes the 402 itself wrong.
     */
    private function warnOnUnscopedMeteredPrice(PaymentSpec $original, PaymentSpec $final): void
    {
        if ($final->free || ! $final->isMetered()) {
            return;
        }

        if ($final->amount === $original->amount || $final->scope !== $original->scope) {
            return;
        }

        if (isset($this->warned[$final->scope])) {
            return;
        }

        $this->warned[$final->scope] = true;

        Log::warning(
            "[mpp] A price resolver changed the amount on metered scope '{$final->scope}' without changing the "
            .'scope. Metered sessions are scope-bound, not payer-bound, so a session bought at one price is '
            .'spendable by any bearer on this scope. Return a distinct `scope` per price tier.'
        );
    }
}
