<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\ChallengeFactory;
use Square1\Mpp\Protocol\ChallengeOffer;

function multiFactory(int $ttl = 300): ChallengeFactory
{
    return new ChallengeFactory('multi-accept-secret', $ttl);
}

function multiSpec(array $offers = []): array
{
    return [
        'method' => 'stripe',
        'amount' => '0.50',
        'currency' => 'USD',
        'grants' => 1,
        'scope' => 'clip.latest',
        'networkId' => 'profile_123',
        'offers' => $offers,
    ];
}

it('keeps the single-method wire shape byte-identical to the legacy formula', function () {
    $factory = multiFactory();
    $now = CarbonImmutable::parse('2026-06-22T12:00:00Z');
    $challenge = $factory->mint(multiSpec(), $now);

    // The legacy canonical/HMAC, reproduced verbatim.
    $legacy = hash_hmac('sha256', implode('|', [
        $challenge->id,
        $challenge->amount,
        $challenge->currency,
        $challenge->method,
        $challenge->networkId ?? '',
        (string) $challenge->grants,
        $challenge->scope,
        $challenge->expiresAt->toIso8601ZuluString(),
    ]), 'multi-accept-secret');

    $doc = $factory->problemDocument($challenge);

    expect($factory->sign($challenge))->toBe($legacy)
        ->and($doc['accepts'])->toHaveCount(1)
        ->and($doc['accepts'][0]['sig'])->toBe($legacy)
        // No `methods` hint in the header when only one method is offered.
        ->and($factory->wwwAuthenticate($challenge))->not->toContain('methods=');
});

it('emits one signed accepts entry per offered method', function () {
    $factory = multiFactory();
    $challenge = $factory->mint(multiSpec([
        new ChallengeOffer('tempo', 'tempo-mainnet', ['stablecoin']),
    ]));

    $doc = $factory->problemDocument($challenge);

    expect($doc['accepts'])->toHaveCount(2)
        ->and($doc['accepts'][0]['method'])->toBe('stripe')
        ->and($doc['accepts'][1]['method'])->toBe('tempo')
        ->and($doc['accepts'][1]['network_id'])->toBe('tempo-mainnet')
        ->and($doc['accepts'][1]['payment_method_types'])->toBe(['stablecoin'])
        // Shared economic fields are identical across accepts.
        ->and($doc['accepts'][1]['amount'])->toBe($doc['accepts'][0]['amount'])
        ->and($doc['accepts'][1]['scope'])->toBe($doc['accepts'][0]['scope']);
});

it('signs each accept independently so a cross-method signature does not validate', function () {
    $factory = multiFactory();
    $challenge = $factory->mint(multiSpec([
        new ChallengeOffer('tempo', 'tempo-mainnet', ['stablecoin']),
    ]));

    $doc = $factory->problemDocument($challenge);
    $stripeSig = $doc['accepts'][0]['sig'];
    $tempoSig = $doc['accepts'][1]['sig'];

    expect($stripeSig)->not->toBe($tempoSig)
        ->and($factory->verifyOffer($challenge, 'stripe', $stripeSig))->toBeTrue()
        ->and($factory->verifyOffer($challenge, 'tempo', $tempoSig))->toBeTrue()
        // A signature minted for one method must not validate another's accept.
        ->and($factory->verifyOffer($challenge, 'tempo', $stripeSig))->toBeFalse()
        ->and($factory->verifyOffer($challenge, 'stripe', $tempoSig))->toBeFalse();
});

it('rejects verification for a method that was not offered', function () {
    $factory = multiFactory();
    $challenge = $factory->mint(multiSpec());

    expect($factory->verifyOffer($challenge, 'tempo', $factory->sign($challenge)))->toBeFalse();
});

it('advertises the offered method set in the header when multiple are offered', function () {
    $factory = multiFactory();
    $challenge = $factory->mint(multiSpec([
        new ChallengeOffer('tempo', 'tempo-mainnet', ['stablecoin']),
    ]));

    expect($factory->wwwAuthenticate($challenge))
        ->toContain('method="stripe"')          // primary advertised as before
        ->toContain('methods="stripe tempo"');   // full set hinted
});
