<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\ChallengeBinding;
use Square1\Mpp\Support\Base64Url;

function bindingChallenge(array $overrides = []): Challenge
{
    return new Challenge(
        id: $overrides['id'] ?? '',
        realm: $overrides['realm'] ?? 'api.example.com',
        method: $overrides['method'] ?? 'stripe',
        intent: $overrides['intent'] ?? 'charge',
        request: $overrides['request'] ?? ['amount' => '50', 'currency' => 'usd'],
        expiresAt: $overrides['expiresAt'] ?? CarbonImmutable::parse('2026-08-18T15:00:00Z'),
        opaque: $overrides['opaque'] ?? [],
        digest: $overrides['digest'] ?? null,
    );
}

it('computes the spec seven-slot pipe-joined HMAC', function () {
    $binding = new ChallengeBinding('test-secret');
    $challenge = bindingChallenge();

    $input = implode('|', [
        'api.example.com', 'stripe', 'charge',
        $challenge->requestB64(),
        '2026-08-18T15:00:00Z',
        '', // digest absent
        '', // opaque absent
    ]);

    $expected = Base64Url::encode(hash_hmac('sha256', $input, 'test-secret', true));

    expect($binding->idFor($challenge))->toBe($expected);
});

it('binds the body digest into slot six when present', function () {
    $binding = new ChallengeBinding('test-secret');
    $digest = 'sha-256=:X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=:';

    $bodyless = $binding->idFor(bindingChallenge());
    $bound = $binding->idFor(bindingChallenge(['digest' => $digest]));
    $otherBody = $binding->idFor(bindingChallenge(['digest' => 'sha-256=:'.base64_encode(hash('sha256', 'other', true)).':']));

    expect($bound)->not->toBe($bodyless)
        ->and($bound)->not->toBe($otherBody);
});

it('leaves body-less challenge ids byte-identical to the pre-digest binding', function () {
    // A null digest must contribute the empty string, not the digest of an
    // empty body: any other choice reissues every GET challenge id in flight.
    $binding = new ChallengeBinding('test-secret');
    $challenge = bindingChallenge();

    $input = implode('|', [
        'api.example.com', 'stripe', 'charge',
        $challenge->requestB64(),
        '2026-08-18T15:00:00Z',
        '',
        '',
    ]);

    expect($challenge->digestParam())->toBe('')
        ->and($binding->idFor($challenge))
        ->toBe(Base64Url::encode(hash_hmac('sha256', $input, 'test-secret', true)));
});

it('rejects a challenge whose digest was swapped after minting', function () {
    $binding = new ChallengeBinding('test-secret');
    $minted = bindingChallenge(['digest' => 'sha-256=:X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=:']);
    $minted = bindingChallenge([
        'id' => $binding->idFor($minted),
        'digest' => 'sha-256=:X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=:',
    ]);

    expect($binding->verify($minted))->toBeTrue();

    $rebodied = bindingChallenge([
        'id' => $minted->id,
        'digest' => 'sha-256=:'.base64_encode(hash('sha256', 'a different body', true)).':',
    ]);
    $stripped = bindingChallenge(['id' => $minted->id]);

    expect($binding->verify($rebodied))->toBeFalse()
        ->and($binding->verify($stripped))->toBeFalse();
});

it('binds opaque into slot seven when present', function () {
    $binding = new ChallengeBinding('test-secret');

    $without = $binding->idFor(bindingChallenge());
    $with = $binding->idFor(bindingChallenge(['opaque' => ['scope' => 'clip']]));

    expect($with)->not->toBe($without);
});

it('verifies an untampered challenge and rejects a tampered one', function () {
    $binding = new ChallengeBinding('test-secret');
    $minted = bindingChallenge();
    $minted = bindingChallenge(['id' => $binding->idFor($minted)]);

    expect($binding->verify($minted))->toBeTrue();

    $tampered = bindingChallenge([
        'id' => $minted->id,
        'request' => ['amount' => '1', 'currency' => 'usd'], // price lowered
    ]);

    expect($binding->verify($tampered))->toBeFalse();
});

it('produces distinct ids when optional slots swap positions', function () {
    $binding = new ChallengeBinding('s');
    $expiresOnly = $binding->id('r', 'm', 'i', 'req', expires: 'X');
    $digestOnly = $binding->id('r', 'm', 'i', 'req', digest: 'X');

    expect($expiresOnly)->not->toBe($digestOnly);
});

it('refuses an empty secret', function () {
    new ChallengeBinding('');
})->throws(InvalidConfigurationException::class);
