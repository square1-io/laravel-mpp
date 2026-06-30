<?php

use Square1\Mpp\Http\Middleware\EnforcePaymentAttributes;
use Square1\Mpp\Http\Middleware\RequirePayment;

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
