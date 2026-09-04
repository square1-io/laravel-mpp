<?php

namespace Square1\Mpp\Payment;

/**
 * The ordered set of settlement rails a route offers, primary first.
 *
 * The SET comes from the first of these that says anything:
 *   1. an explicit per-route list (middleware `methods=…`, attribute `methods:`);
 *   2. an explicit single rail (middleware `method=…`, attribute `method:`);
 *   3. `config('mpp.accept')`;
 *   4. `config('mpp.default_method')` alone.
 *
 * The PRIMARY — the rail that leads the challenge set, and so the one a client
 * that does not negotiate will answer — is the head of that set, whatever its
 * source. A single `method=` overrides it and is hoisted to the front, added if
 * the set does not already hold it. One rule for every source, so a
 * pipe-separated list orders the rails identically in a route and in config.
 *
 * `default_method` therefore chooses the rail for routes that name none, and
 * never reorders a set that does.
 *
 * Lives in its own class because two callers need the same answer: the gate,
 * via SpecResolver, when minting a 402, and DiscoveryDocument when advertising
 * the same route in `/openapi.json`. They were separate implementations of this
 * rule, and duplicating it is exactly how the advertised order and the minted
 * order come to disagree.
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

        // De-duplicate while preserving order.
        $offered = array_values(array_unique(array_map('strval', $offered)));

        $primary = $explicit ? $method : $offered[0];

        return [$primary, ...array_values(array_filter($offered, fn (string $m) => $m !== $primary))];
    }
}
