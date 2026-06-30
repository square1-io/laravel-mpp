<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Protocol\ChallengeFactory;

function challengeFactory(int $ttl = 300): ChallengeFactory
{
    return new ChallengeFactory('unit-test-secret', $ttl);
}

function challengeSpec(array $overrides = []): array
{
    return array_merge([
        'method' => 'stripe',
        'amount' => '0.50',
        'currency' => 'USD',
        'grants' => 1,
        'scope' => 'clip.latest',
        'networkId' => 'profile_123',
    ], $overrides);
}

it('mints a signed challenge that verifies', function () {
    $factory = challengeFactory();
    $challenge = $factory->mint(challengeSpec());

    expect($challenge->id)->toStartWith('chal_')
        ->and($factory->verify($challenge, $factory->sign($challenge)))->toBeTrue();
});

it('rejects a tampered signature', function () {
    $factory = challengeFactory();
    $signature = $factory->sign($factory->mint(challengeSpec()));

    expect($factory->verify($factory->mint(challengeSpec(['grants' => 100])), $signature))->toBeFalse();
});

it('does not verify a signature made with a different secret', function () {
    $challenge = challengeFactory()->mint(challengeSpec());

    expect(challengeFactory()->verify($challenge, (new ChallengeFactory('other-secret'))->sign($challenge)))->toBeFalse();
});

it('honours the ttl for expiry', function () {
    $now = CarbonImmutable::parse('2026-06-18T12:00:00Z');
    $challenge = challengeFactory(300)->mint(challengeSpec(), $now);

    expect($challenge->isExpired($now->addSeconds(299)))->toBeFalse()
        ->and($challenge->isExpired($now->addSeconds(301)))->toBeTrue();
});

it('encodes a WWW-Authenticate header', function () {
    $factory = challengeFactory();
    $header = $factory->wwwAuthenticate($factory->mint(challengeSpec()));

    expect($header)->toStartWith('Payment ')
        ->toContain('method="stripe"')->toContain('amount="0.50"')
        ->toContain('scope="clip.latest"')->toContain('sig="');
});

it('builds a problem document', function () {
    $factory = challengeFactory();
    $challenge = $factory->mint(challengeSpec(['grants' => 10, 'amount' => '5.00']));
    $doc = $factory->problemDocument($challenge);

    expect($doc['status'])->toBe(402)
        ->and($doc['accepts'][0]['amount'])->toBe('5.00')
        ->and($doc['accepts'][0]['grants'])->toBe(10)
        ->and($factory->verify($challenge, $doc['accepts'][0]['sig']))->toBeTrue();
});

it('requires a secret', function () {
    new ChallengeFactory('');
})->throws(InvalidConfigurationException::class);
