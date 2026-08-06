<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\PaymentSpec;

function spec(array $overrides = []): PaymentSpec
{
    return new PaymentSpec(
        amount: $overrides['amount'] ?? '5.00',
        currency: $overrides['currency'] ?? 'USD',
        grants: $overrides['grants'] ?? 1,
        scope: $overrides['scope'] ?? 'report.basic',
        method: 'stripe',
        networkId: 'profile_123',
        paymentMethodTypes: ['card'],
        offeredMethods: ['stripe', 'other'],
        preconditions: ['postexists'],
        pricing: ['tiered'],
    );
}

// ── Accepted overrides ──────────────────────────────────────────────────────

it('returns a new spec and leaves the original untouched', function () {
    $original = spec();
    $priced = $original->with(['amount' => '2.00']);

    expect($priced)->not->toBe($original)
        ->and($priced->amount)->toBe('2.00')
        ->and($original->amount)->toBe('5.00');
});

it('carries the rail fields and the route’s lists through unchanged', function () {
    $priced = spec()->with(['amount' => '2.00', 'scope' => 'report.pro']);

    expect($priced->method)->toBe('stripe')
        ->and($priced->networkId)->toBe('profile_123')
        ->and($priced->paymentMethodTypes)->toBe(['card'])
        ->and($priced->offeredMethods)->toBe(['stripe', 'other'])
        ->and($priced->preconditions)->toBe(['postexists'])
        ->and($priced->pricing)->toBe(['tiered']);
});

it('accepts an amount given as a float or an int', function () {
    expect(spec()->with(['amount' => 2.5])->amount)->toBe('2.5')
        ->and(spec()->with(['amount' => 3])->amount)->toBe('3');
});

it('trims and upper-cases the currency', function () {
    expect(spec()->with(['currency' => ' eur '])->currency)->toBe('EUR');
});

it('accepts grants as an int or a digit string', function () {
    expect(spec()->with(['grants' => 20])->grants)->toBe(20)
        ->and(spec()->with(['grants' => '20'])->grants)->toBe(20);
});

it('trims the scope', function () {
    expect(spec()->with(['scope' => ' report.pro '])->scope)->toBe('report.pro');
});

it('keeps the untouched fields when only one is overridden', function () {
    $priced = spec(['grants' => 10])->with(['amount' => '2.00']);

    expect($priced->currency)->toBe('USD')
        ->and($priced->grants)->toBe(10)
        ->and($priced->scope)->toBe('report.basic')
        ->and($priced->free)->toBeFalse();
});

it('marks the spec free without disturbing the amount it would have charged', function () {
    $free = spec()->with(['free' => true]);

    expect($free->free)->toBeTrue()
        ->and($free->amount)->toBe('5.00');
});

it('is not free by default', function () {
    expect(spec()->free)->toBeFalse()
        ->and(spec()->with(['amount' => '2.00'])->free)->toBeFalse();
});

it('leaves an earlier free flag standing when a later override is silent about it', function () {
    expect(spec()->with(['free' => true])->with(['scope' => 'report.pro'])->free)->toBeTrue();
});

// ── Rejected overrides ──────────────────────────────────────────────────────

it('rejects an unknown override key', function () {
    spec()->with(['ammount' => '2.00']);
})->throws(InvalidConfigurationException::class, 'unknown override(s): ammount');

it('names every unknown key at once', function () {
    spec()->with(['method' => 'tempo', 'nope' => 1]);
})->throws(InvalidConfigurationException::class, 'unknown override(s): method, nope');

it('rejects a rail override even though the spec holds one', function () {
    spec()->with(['offeredMethods' => ['tempo']]);
})->throws(InvalidConfigurationException::class, 'unknown override(s): offeredMethods');

it('rejects a zero amount', function () {
    spec()->with(['amount' => '0.00']);
})->throws(InvalidConfigurationException::class, "invalid 'amount' (0.00)");

it('rejects a negative amount', function () {
    spec()->with(['amount' => '-2.00']);
})->throws(InvalidConfigurationException::class, "invalid 'amount' (-2.00)");

it('rejects an empty amount', function () {
    spec()->with(['amount' => '']);
})->throws(InvalidConfigurationException::class, "invalid 'amount' ('')");

it('rejects a non-numeric amount', function () {
    spec()->with(['amount' => 'free']);
})->throws(InvalidConfigurationException::class, "invalid 'amount' (free)");

it('rejects a null amount', function () {
    spec()->with(['amount' => null]);
})->throws(InvalidConfigurationException::class, "non-numeric 'amount'");

it('points at free => true when an amount is rejected', function () {
    spec()->with(['amount' => '0']);
})->throws(InvalidConfigurationException::class, "to waive the charge return `'free' => true` instead");

it('rejects a non-boolean free', function () {
    spec()->with(['free' => 'yes']);
})->throws(InvalidConfigurationException::class, "non-boolean 'free'");

it('rejects free and amount together', function () {
    spec()->with(['free' => true, 'amount' => '2.00']);
})->throws(InvalidConfigurationException::class, "returned both 'free' => true and an 'amount'");

it('allows free => false alongside an amount', function () {
    expect(spec()->with(['free' => false, 'amount' => '2.00']))
        ->free->toBeFalse()
        ->amount->toBe('2.00');
});

it('rejects an empty currency', function () {
    spec()->with(['currency' => '  ']);
})->throws(InvalidConfigurationException::class, "empty 'currency'");

it('rejects a non-string currency', function () {
    spec()->with(['currency' => 978]);
})->throws(InvalidConfigurationException::class, "empty 'currency'");

it('rejects grants below one', function () {
    spec()->with(['grants' => 0]);
})->throws(InvalidConfigurationException::class, "invalid 'grants'");

it('rejects negative grants', function () {
    spec()->with(['grants' => -5]);
})->throws(InvalidConfigurationException::class, "invalid 'grants'");

it('rejects a fractional grants value', function () {
    spec()->with(['grants' => '2.5']);
})->throws(InvalidConfigurationException::class, "invalid 'grants'");

it('rejects an empty scope', function () {
    spec()->with(['scope' => ' ']);
})->throws(InvalidConfigurationException::class, "empty 'scope'");
