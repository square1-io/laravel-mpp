<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Receipt;
use Square1\Mpp\Settlement\SettlementResult;

function aChallenge(string $method = 'stripe'): Challenge
{
    return new Challenge('chal_1', $method, '0.50', 'USD', 1, 'clip', CarbonImmutable::parse('2026-06-22T12:05:00Z'));
}

it('exposes a rail-neutral settlementRef', function () {
    $result = SettlementResult::settled('ref_123', 50, 'USD');

    expect($result->succeeded)->toBeTrue()
        ->and($result->settlementRef)->toBe('ref_123')
        ->and($result->currency)->toBe('USD');
});

it('renders a stripe receipt with a rail-neutral ref and no paymentIntent attribute', function () {
    $receipt = Receipt::fromSettlement(aChallenge('stripe'), SettlementResult::settled('pi_1', 50, 'USD'), 'stripe');
    $header = $receipt->header();

    expect($header)
        ->toContain('method="stripe"')
        ->toContain('ref="pi_1"')
        ->not->toContain('paymentIntent=')
        ->and($receipt->settlementRef)->toBe('pi_1');
});

it('renders a non-stripe receipt with the same rail-neutral ref', function () {
    $receipt = Receipt::fromSettlement(aChallenge('tempo'), SettlementResult::settled('0xabc', 50, 'USD'), 'tempo');
    $header = $receipt->header();

    expect($header)
        ->toContain('method="tempo"')
        ->toContain('ref="0xabc"')
        ->not->toContain('paymentIntent=');
});
