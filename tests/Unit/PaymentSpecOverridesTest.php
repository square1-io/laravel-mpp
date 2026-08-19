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

it('rejects an override it cannot honour', function (array $overrides, string $message) {
    expect(fn () => spec()->with($overrides))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'unknown key' => [['ammount' => '2.00'], 'unknown override(s): ammount'],
    'every unknown key at once' => [['method' => 'tempo', 'nope' => 1], 'unknown override(s): method, nope'],
    'a rail field the spec does hold' => [['offeredMethods' => ['tempo']], 'unknown override(s): offeredMethods'],
    'zero amount' => [['amount' => '0.00'], "invalid 'amount' (0.00)"],
    'negative amount' => [['amount' => '-2.00'], "invalid 'amount' (-2.00)"],
    'empty amount' => [['amount' => ''], "invalid 'amount' ('')"],
    'non-numeric amount' => [['amount' => 'free'], "invalid 'amount' (free)"],
    'scientific notation amount' => [['amount' => '1e2'], "invalid 'amount' (1e2)"],
    'explicitly signed amount' => [['amount' => '+2.00'], "invalid 'amount' (+2.00)"],
    'null amount' => [['amount' => null], "non-numeric 'amount'"],
    'non-boolean free' => [['free' => 'yes'], "non-boolean 'free'"],
    'free and amount together' => [['free' => true, 'amount' => '2.00'], "returned both 'free' => true and an 'amount'"],
    'empty currency' => [['currency' => '  '], "empty 'currency'"],
    'non-string currency' => [['currency' => 978], "empty 'currency'"],
    'grants below one' => [['grants' => 0], "invalid 'grants'"],
    'negative grants' => [['grants' => -5], "invalid 'grants'"],
    'fractional grants' => [['grants' => '2.5'], "invalid 'grants'"],
    'empty scope' => [['scope' => ' '], "empty 'scope'"],
]);

it('points a rejected amount at free => true rather than leaving it a mystery', function () {
    spec()->with(['amount' => '0']);
})->throws(InvalidConfigurationException::class, "to waive the charge return `'free' => true` instead");

it('allows free => false alongside an amount', function () {
    expect(spec()->with(['free' => false, 'amount' => '2.00']))
        ->free->toBeFalse()
        ->amount->toBe('2.00');
});
