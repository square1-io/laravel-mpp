<?php

namespace Square1\Mpp\Settlement;

use Illuminate\Contracts\Container\Container;
use Square1\Mpp\Exceptions\InvalidConfigurationException;

/**
 * Resolves the configured Verifier for a given settlement method through the
 * container, so an application (or a test) can rebind a method's verifier
 * without touching the protocol layer.
 */
class VerifierFactory
{
    public function __construct(private readonly Container $container) {}

    public function make(string $method): Verifier
    {
        $class = config("mpp.methods.{$method}.verifier");

        if (! is_string($class) || $class === '') {
            throw new InvalidConfigurationException("No verifier configured for payment method '{$method}'.");
        }

        $verifier = $this->container->make($class);

        if (! $verifier instanceof Verifier) {
            throw new InvalidConfigurationException(
                "Configured verifier for '{$method}' must implement ".Verifier::class.'.'
            );
        }

        return $verifier;
    }
}
