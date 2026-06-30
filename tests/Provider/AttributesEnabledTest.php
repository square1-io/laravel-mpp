<?php

use Square1\Mpp\Http\Middleware\EnforcePaymentAttributes;

it('registers the enforcer on the configured groups when enabled', function () {
    $groups = app('router')->getMiddlewareGroups();

    expect($groups['web'] ?? [])->toContain(EnforcePaymentAttributes::class)
        ->and($groups['api'] ?? [])->toContain(EnforcePaymentAttributes::class);
});
