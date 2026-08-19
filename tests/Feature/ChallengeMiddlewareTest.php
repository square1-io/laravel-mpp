<?php

use Square1\Mpp\Support\Base64Url;

it('returns a well-formed spec 402 for an unpaid request', function () {
    $response = $this->get('/clip');

    $response->assertStatus(402)
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');

    $response->assertJsonPath('status', 402)
        ->assertJsonPath('title', 'Payment Required')
        ->assertJsonPath('type', 'https://paymentauth.org/problems/payment-required');

    // The body carries no economic terms — those live in the challenge only.
    expect($response->json('accepts'))->toBeNull();

    $challenge = parseChallenges($response->headers->get('WWW-Authenticate'))[0];

    expect($challenge['method'])->toBe('stripe')
        ->and($challenge['intent'])->toBe('charge')
        ->and($challenge['realm'])->not->toBeEmpty()
        ->and($challenge['expires'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

    $request = json_decode((string) Base64Url::decode($challenge['request']), true);

    expect($request['amount'])->toBe('50')
        ->and($request['currency'])->toBe('usd');
});

it('makes the challenge id the verifiable binding and echoes it in the body', function () {
    $response = $this->get('/clip');
    $challenge = parseChallenges($response->headers->get('WWW-Authenticate'))[0];

    expect($response->json('challengeId'))->toBe($challenge['id'])
        ->and(strlen($challenge['id']))->toBeGreaterThanOrEqual(43); // 32-byte HMAC, base64url
});

it('advertises the metered bundle in bound opaque data', function () {
    $response = $this->get('/report');
    $challenge = parseChallenges($response->headers->get('WWW-Authenticate'))[0];

    $request = json_decode((string) Base64Url::decode($challenge['request']), true);
    $opaque = json_decode((string) Base64Url::decode($challenge['opaque'] ?? ''), true);

    expect($request['amount'])->toBe('500') // 5.00 USD bundle price, minor units
        ->and($opaque['grants'])->toBe('10')
        ->and($opaque['scope'])->not->toBeEmpty();
});
