<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Receipt;
use Square1\Mpp\Settlement\SettlementResult;

function aChallenge(string $method = 'stripe'): Challenge
{
    $request = $method === 'tempo'
        ? ['amount' => '500000', 'currency' => '0x20C000000000000000000000b9537d11c60E8b50']
        : ['amount' => '50', 'currency' => 'usd'];

    return new Challenge(
        id: 'chal_1',
        realm: 'api.test',
        method: $method,
        intent: 'charge',
        request: $request,
        expiresAt: CarbonImmutable::parse('2026-06-22T12:05:00Z'),
        opaque: ['scope' => 'clip'],
    );
}

it('exposes a rail-neutral settlementRef', function () {
    $result = SettlementResult::settled('ref_123', 50, 'USD');

    expect($result->succeeded)->toBeTrue()
        ->and($result->settlementRef)->toBe('ref_123')
        ->and($result->currency)->toBe('USD');
});

it('renders a spec base64url receipt for a stripe settlement', function () {
    $receipt = Receipt::fromSettlement(aChallenge('stripe'), SettlementResult::settled('pi_1', 50, 'USD'), 'stripe');
    $decoded = decodeReceipt($receipt->header());

    expect($decoded['status'])->toBe('success')
        ->and($decoded['method'])->toBe('stripe')
        ->and($decoded['reference'])->toBe('pi_1')
        ->and($decoded['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T/')
        ->and($receipt->settlementRef)->toBe('pi_1');
});

it('renders the same receipt shape for a tempo settlement', function () {
    $receipt = Receipt::fromSettlement(aChallenge('tempo'), SettlementResult::settled('0xabc'), 'tempo');
    $decoded = decodeReceipt($receipt->header());

    expect($decoded['method'])->toBe('tempo')
        ->and($decoded['reference'])->toBe('0xabc');
});

it('carries exactly the spec receipt fields, never an amount or challengeId', function () {
    // draft-httpauth-payment-00 §5.3 and the stripe / tempo charge drafts define
    // the receipt as {status, method, timestamp, reference}, and reserve extra
    // fields for method specifications. Amount and currency are deliberately
    // absent: the payer holds the exact terms in the challenge they echoed, and
    // rendering them here forced a units choice the rest of the wire format
    // never makes.
    foreach (['stripe' => SettlementResult::settled('pi_1', 50, 'USD'), 'tempo' => SettlementResult::settled('0xabc', '500000')] as $method => $result) {
        $decoded = decodeReceipt(Receipt::fromSettlement(aChallenge($method), $result, $method)->header());

        // JCS sorts keys, so the key order is the wire order.
        expect(array_keys($decoded))->toBe(['method', 'reference', 'status', 'timestamp']);
    }
});
