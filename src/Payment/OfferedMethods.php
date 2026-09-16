<?php

namespace Square1\Mpp\Payment;

/**
 * The ordered set of settlement rails that a route offers, primary first.
 *
 * The SET comes from the first of these sources that states anything:
 *   1. an explicit per-route list (middleware `methods=…`, attribute `methods:`);
 *   2. an explicit single rail (middleware `method=…`, attribute `method:`);
 *   3. `config('mpp.accept')`;
 *   4. `config('mpp.default_method')` alone.
 *
 * The PRIMARY rail leads the challenge set. A client that does not negotiate
 * answers that rail. The primary rail is the first entry of the set, whatever
 * the source of the set. A single `method=` overrides the primary rail and moves
 * to the front. The class adds it when the set does not already hold it. One
 * rule covers every source, so a pipe-separated list orders the rails in the
 * same way in a route and in the config.
 *
 * `default_method` therefore chooses the rail for a route that names none. It
 * never reorders a set that a route or the config states.
 *
 * This rule has its own class because two callers need the same answer. The gate
 * needs it through SpecResolver, when it mints a 402. DiscoveryDocument needs it
 * when it advertises the same route in `/openapi.json`. Each class once held its
 * own implementation of the rule. Two implementations are how the advertised
 * order and the minted order come to differ.
 */
final class OfferedMethods
{
    /**
     * @param  list<string>|null  $methods  an explicit ordered per-route set, or null for the configured default
     * @return non-empty-list<string> ordered, primary first, de-duplicated
     */
    public static function resolve(?string $method, ?array $methods): array
    {
        $default = (string) config('mpp.default_method', 'stripe');
        $explicit = $method !== null && $method !== '';
        $explicitList = is_array($methods) && $methods !== [];

        if ($explicitList) {
            $offered = $methods;
        } elseif ($explicit) {
            $offered = [$method];
        } else {
            $accept = config('mpp.accept');
            $offered = is_array($accept) && $accept !== [] ? $accept : [$default];
        }

        if ($offered === []) {
            $offered = [$default];
        }

        // Remove the duplicates and keep the order.
        $offered = array_values(array_unique(array_map('strval', $offered)));

        $primary = $explicit ? $method : $offered[0];

        return [$primary, ...array_values(array_filter($offered, fn (string $m) => $m !== $primary))];
    }
}
