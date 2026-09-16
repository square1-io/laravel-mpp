<?php

namespace Square1\Mpp\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\Concerns\ResolvesNamedCallables;

/**
 * Applies the named price resolvers of a route to its resolved PaymentSpec.
 *
 * The price can then depend on the request, for example on a plan tier, a
 * region, or the size of the resource. The route definition does not fix it.
 *
 * Each name resolves from `mpp.pricing.resolvers` to a [Class::class, 'method']
 * pair. The container resolves the pair, so the list survives `config:cache`.
 * The package calls the pair with the Request and the current spec. The pair
 * returns an array of overrides (`amount`, `currency`, `grants`, `scope`, or
 * `free => true`), or null to leave the price as it is.
 *
 * The global resolvers run first, and then the resolvers of the route. Both run
 * in the declared order, and the package removes duplicates. Each resolver sees
 * the result of the resolvers before it. An unknown name throws, so a typo
 * cannot fall back to the static price without a message.
 *
 * The price is advisory until the package mints it. The amount that a buyer
 * pays is the amount in the signed challenge, and settlement verifies against
 * that challenge and never against a new spec. A resolver that returns a
 * different answer between the 402 and the paid retry therefore cannot change
 * the amount that the package quoted to the buyer.
 */
class PriceResolver
{
    use ResolvesNamedCallables;

    /**
     * The route scopes that this process has already warned about.
     *
     * The number of gated routes bounds this list. The class records an entry
     * only for a scope that every resolver left as the route declared it. See
     * warnOnUnscopedMeteredPrice.
     *
     * @var array<string, bool>
     */
    private array $warned = [];

    /**
     * Returns the resolvers that apply to this spec, in the order that they
     * run: the global list first, then the list of the route, without
     * duplicates.
     *
     * This method is public because the pipeline names these resolvers when it
     * reports that nothing priced a request. That message must describe what
     * ran, so both callers read the set here instead of building it again.
     *
     * @return list<string>
     */
    public function namesFor(PaymentSpec $spec): array
    {
        return $this->namedList('mpp.pricing.global', $spec->pricing);
    }

    public function apply(Request $request, PaymentSpec $spec): PaymentSpec
    {
        $names = $this->namesFor($spec);

        if ($names === []) {
            return $spec;
        }

        $resolvers = (array) config('mpp.pricing.resolvers', []);
        $original = $spec;

        foreach ($names as $name) {
            [$class, $method] = $this->namedCallable($resolvers, $name, 'price resolver', 'mpp.pricing.resolvers');

            $overrides = app($class)->{$method}($request, $spec);

            if ($overrides === null || $overrides === []) {
                continue;
            }

            if (! is_array($overrides)) {
                throw new InvalidConfigurationException(
                    "The price resolver '{$name}' must return an array of overrides, or null. It returned ".get_debug_type($overrides).'.'
                );
            }

            $spec = $spec->with($overrides);
        }

        $this->warnOnUnscopedMeteredPrice($original, $spec);

        return $spec;
    }

    /**
     * Warns when a metered route varies its price but not its scope.
     *
     * A session is bound to a scope and not to a payer. `consume()` checks only
     * that the server issued the session for this scope. On a metered route
     * whose price varies per request, any holder of the session id can therefore
     * spend a session that someone bought at the cheap tier. That includes a
     * holder who owes more. To vary the `scope` with the price keeps the tiers
     * in separate credit pools.
     *
     * The class warns once per scope per process, and does not enforce the rule.
     * To share one scope across tiers is a valid choice, although an unusual
     * one, and it does not make the 402 itself incorrect.
     *
     * The flag uses the scope of the ROUTE as its key. The number of gated
     * routes therefore bounds `$warned`. A resolver that varies the scope per
     * request is what this warning asks for, and such a resolver never reaches
     * the flag.
     */
    private function warnOnUnscopedMeteredPrice(PaymentSpec $original, PaymentSpec $final): void
    {
        if ($final->free || ! $final->isMetered()) {
            return;
        }

        if ($final->amount === $original->amount || $final->scope !== $original->scope) {
            return;
        }

        if (isset($this->warned[$original->scope])) {
            return;
        }

        $this->warned[$original->scope] = true;

        Log::warning(
            "[mpp] A price resolver changed the amount on the metered scope '{$final->scope}', and did not "
            .'change the scope. A metered session is bound to a scope, and not to a payer. Any holder can '
            .'therefore spend a session that someone bought at one price within this scope. Return a '
            .'separate `scope` for each price tier.'
        );
    }
}
