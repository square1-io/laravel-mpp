<?php

use Square1\Mpp\Protocol\AcceptPayment;

it('returns the server order untouched when the header is absent', function () {
    expect(AcceptPayment::parse(null)->rank(['stripe', 'tempo'], 'charge'))
        ->toBe(['stripe', 'tempo']);
});

it('filters to declared methods', function () {
    $accept = AcceptPayment::parse('stripe/charge');
    expect($accept->rank(['tempo', 'stripe'], 'charge'))->toBe(['stripe']);
});

it('orders by descending q', function () {
    $accept = AcceptPayment::parse('tempo/charge, stripe/charge;q=0.3');
    expect($accept->rank(['stripe', 'tempo'], 'charge'))->toBe(['tempo', 'stripe']);
});

it('preserves server order on equal q', function () {
    $accept = AcceptPayment::parse('tempo/charge, stripe/charge');
    expect($accept->rank(['stripe', 'tempo'], 'charge'))->toBe(['stripe', 'tempo']);
});

it('supports wildcards with specificity winning', function () {
    $accept = AcceptPayment::parse('tempo/*, */charge;q=0.2, stripe/charge;q=0.5');
    expect($accept->rank(['stripe', 'tempo', 'solana'], 'charge'))
        ->toBe(['tempo', 'stripe', 'solana']);
});

it('excludes q=0 methods', function () {
    $accept = AcceptPayment::parse('tempo/charge, stripe/charge;q=0');
    expect($accept->rank(['stripe', 'tempo'], 'charge'))->toBe(['tempo']);
});

it('falls back to the full set when nothing matches', function () {
    $accept = AcceptPayment::parse('solana/charge');
    expect($accept->rank(['stripe', 'tempo'], 'charge'))->toBe(['stripe', 'tempo']);
});

it('ignores malformed entries but keeps valid ones', function () {
    $accept = AcceptPayment::parse('!!bad!!, stripe/charge');
    expect($accept->rank(['stripe', 'tempo'], 'charge'))->toBe(['stripe']);
});
