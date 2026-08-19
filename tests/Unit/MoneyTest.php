<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Support\Money;

it('converts decimals to minor units', function (string $amount, string $currency, int $expected) {
    expect(Money::toMinorUnits($amount, $currency))->toBe($expected);
})->with([
    ['0.50', 'USD', 50],
    ['1.00', 'USD', 100],
    ['0.1', 'USD', 10],
    ['12.34', 'USD', 1234],
    ['5', 'USD', 500],
    ['5', 'JPY', 5],
    ['1000', 'JPY', 1000],
]);

it('converts minor units to decimals', function (int $minor, string $currency, string $expected) {
    expect(Money::fromMinorUnits($minor, $currency))->toBe($expected);
})->with([
    [50, 'USD', '0.50'],
    [100, 'USD', '1.00'],
    [1234, 'USD', '12.34'],
    [5, 'JPY', '5'],
]);

it('round-trips decimals through minor units', function (string $amount) {
    expect(Money::fromMinorUnits(Money::toMinorUnits($amount, 'USD'), 'USD'))->toBe($amount);
})->with(['0.50', '1.00', '12.34', '99.99']);

it('rejects invalid amounts', function () {
    Money::toMinorUnits('not-a-number', 'USD');
})->throws(InvalidConfigurationException::class);

it('rejects amounts with more precision than the currency supports', function (string $amount, string $currency) {
    Money::toMinorUnits($amount, $currency);
})->with([
    ['0.009', 'USD'],
    ['5.01', 'JPY'],
])->throws(InvalidConfigurationException::class);

it('charges ISK and UGX as whole units (two-decimal ending in 00)', function (string $amount, string $currency, int $expected) {
    expect(Money::toMinorUnits($amount, $currency))->toBe($expected);
})->with([
    ['5', 'ISK', 500],
    ['5.00', 'ISK', 500],
    ['5', 'UGX', 500],
    ['5.00', 'UGX', 500],
]);

it('rejects a fractional ISK or UGX amount Stripe forbids', function (string $amount, string $currency) {
    Money::toMinorUnits($amount, $currency);
})->with([
    ['5.01', 'ISK'],
    ['5.01', 'UGX'],
])->throws(InvalidConfigurationException::class, 'whole');

it('fails closed on an amount above PHP_INT_MAX instead of saturating', function () {
    // Two-decimal, so minor units are amount * 100. This whole part alone exceeds
    // PHP_INT_MAX in minor units.
    Money::toMinorUnits('999999999999999999999', 'USD');
})->throws(InvalidConfigurationException::class, 'maximum supported');
