<?php

use Square1\Mpp\Http\Middleware\EnforcePaymentAttributes;
use Square1\Mpp\Http\Middleware\RequirePayment;
use Square1\Mpp\Payment\PreconditionRunner;
use Square1\Mpp\Payment\PriceResolver;

it('merges the package config defaults', function () {
    expect(config('mpp.default_method'))->toBe('stripe')
        ->and(config('mpp.challenge_ttl'))->toBe(300)
        ->and(config('mpp.sessions.driver'))->toBe('cache')
        ->and(config('mpp.attributes.enabled'))->toBeFalse();
});

it('aliases the mpp middleware', function () {
    expect(app('router')->getMiddleware()['mpp'] ?? null)
        ->toBe(RequirePayment::class);
});

it('does not register the attribute enforcer on route groups by default', function () {
    $groups = app('router')->getMiddlewareGroups();

    expect($groups['web'] ?? [])->not->toContain(EnforcePaymentAttributes::class);
});

it('ships no price resolvers, so pricing stays static until one is configured', function () {
    // Read the shipped file rather than the merged config: the test case
    // registers fake resolvers over the top of it.
    $shipped = require __DIR__.'/../../config/mpp.php';

    expect($shipped['pricing']['resolvers'])->toBe([])
        ->and($shipped['pricing']['global'])->toBe([]);
});

it('shares the price resolver so its metered-scope warning is logged once per process', function () {
    expect(app(PriceResolver::class))->toBe(app(PriceResolver::class));
});

it('shares the precondition runner', function () {
    expect(app(PreconditionRunner::class))->toBe(app(PreconditionRunner::class));
});
