<?php

namespace Square1\Mpp\Payment\Concerns;

use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Shared mechanics for the package's named-callable registries — the precondition
 * checks and the price resolvers. Both let an application register work under a
 * name in config, apply a global list to every gated route, and let a route add
 * its own; both resolve a name to a [Class::class, 'method'] pair through the
 * container so the registry survives `config:cache`.
 *
 * Only the lookup is shared. What each does with the result — veto the request
 * with a Response, or fold overrides into a new PaymentSpec — is theirs alone.
 */
trait ResolvesNamedCallables
{
    /**
     * The ordered list of names to run: the global list first, then the route's
     * own, de-duplicated so a name listed in both runs once.
     *
     * @param  list<string>  $routeNames
     * @return list<string>
     */
    private function namedList(string $globalConfigKey, array $routeNames): array
    {
        return array_values(array_unique(array_merge(
            (array) config($globalConfigKey, []),
            $routeNames,
        )));
    }

    /**
     * Resolve one name to its [Class::class, 'method'] pair. An unknown or
     * malformed entry throws, so a typo fails closed rather than silently
     * skipping the work the name stood for.
     *
     * @param  array<string, mixed>  $registry
     * @return array{0: string, 1: string}
     */
    private function namedCallable(array $registry, string $name, string $noun, string $registryConfigKey): array
    {
        $entry = $registry[$name] ?? null;

        if (! is_array($entry) || count($entry) !== 2 || ! is_string($entry[0])) {
            throw new InvalidConfigurationException(
                "Unknown {$noun} '{$name}'. Define it under {$registryConfigKey} "
                ."as a [Class::class, 'method'] pair."
            );
        }

        return [$entry[0], (string) $entry[1]];
    }
}
