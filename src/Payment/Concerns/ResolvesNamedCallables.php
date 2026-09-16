<?php

namespace Square1\Mpp\Payment\Concerns;

use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * The shared mechanics for the named-callable registries of the package, which
 * are the precondition checks and the price resolvers.
 *
 * Both registries let an application register work under a name in the config,
 * apply a global list to every gated route, and let a route add its own names.
 * Both resolve a name to a [Class::class, 'method'] pair through the container,
 * so the registry survives `config:cache`.
 *
 * Only the lookup is shared. Each registry then does its own work with the
 * result. A precondition check rejects the request with a Response. A price
 * resolver folds its overrides into a new PaymentSpec.
 */
trait ResolvesNamedCallables
{
    /**
     * Returns the ordered list of names to run: the global list first, then the
     * list of the route, without duplicates. A name in both lists therefore runs
     * once.
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
     * Resolves one name to its [Class::class, 'method'] pair.
     *
     * The method throws on an unknown or malformed entry. A typo therefore fails
     * closed, and does not skip the work that the name stood for.
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
