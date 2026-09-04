<?php

use Square1\Mpp\Tests\Fakes\FakeTempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeVerifier;

/**
 * Multi-rail behaviour on the spec wire: `/multi` offers stripe + tempo, so a
 * single 402 carries TWO `Payment …` challenges — each with its own binding
 * id — and the client answers exactly one.
 */
beforeEach(function () {
    FakeVerifier::reset();
    FakeTempoVerifier::reset();
});

it('offers one challenge per rail in a single 402', function () {
    $response = $this->get('/multi')->assertStatus(402);

    $challenges = challengesFrom($response);
    $byMethod = array_column($challenges, null, 'method');

    expect($challenges)->toHaveCount(2)
        ->and($byMethod)->toHaveKeys(['stripe', 'tempo'])
        ->and($byMethod['stripe']['id'])->not->toBe($byMethod['tempo']['id'])
        ->and($byMethod['stripe']['intent'])->toBe('charge')
        ->and($byMethod['tempo']['intent'])->toBe('charge');
});

it('puts each challenge on its own WWW-Authenticate field line', function () {
    $response = $this->get('/multi')->assertStatus(402);

    // Repeated field lines, not one comma-joined value: the form the spec
    // illustrates (draft-httpauth-payment-00 B.2) and the unambiguous one to
    // parse. Asserted on the response because `headers->get()` returns only the
    // first line, so a regression here is invisible to any test using it.
    $lines = $response->headers->all('WWW-Authenticate');

    expect($lines)->toHaveCount(2)
        ->and(parseChallenges($lines[0]))->toHaveCount(1)
        ->and(parseChallenges($lines[1]))->toHaveCount(1);
});

it('collapses to a single field line when one rail is offered', function () {
    $response = $this->get('/clip')->assertStatus(402);

    expect($response->headers->all('WWW-Authenticate'))->toHaveCount(1);
});

it('filters and ranks the offered rails by Accept-Payment', function () {
    $only = $this->withHeader('Accept-Payment', 'tempo/charge')->get('/multi');
    $ranked = $this->withHeader('Accept-Payment', 'tempo/charge, stripe/charge;q=0.2')->get('/multi');
    $unknown = $this->withHeader('Accept-Payment', 'solana/charge')->get('/multi');

    $methods = fn ($r) => array_column(challengesFrom($r), 'method');

    expect($methods($only))->toBe(['tempo'])
        ->and($methods($ranked))->toBe(['tempo', 'stripe'])
        // No match: the header is ignored and the full set returned, per spec.
        ->and($methods($unknown))->toBe(['stripe', 'tempo']);
});

it('routes a stripe credential to the stripe verifier', function () {
    $challenge = getChallenge($this, '/multi', 'stripe');

    $response = $this->withHeaders([
        'Authorization' => paymentCredential($challenge, ['spt' => 'spt_x']),
    ])->get('/multi');

    $response->assertOk()->assertSee('MULTI');

    expect(FakeVerifier::$calls)->toBe(1)
        ->and(FakeTempoVerifier::$calls)->toBe(0)
        ->and(decodeReceipt($response->headers->get('Payment-Receipt'))['method'])->toBe('stripe');
});

it('routes a tempo credential to the tempo verifier', function () {
    $challenge = getChallenge($this, '/multi', 'tempo');

    $response = $this->withHeaders([
        'Authorization' => paymentCredential($challenge, ['type' => 'transaction', 'signature' => '0x76abc']),
    ])->get('/multi');

    $response->assertOk()->assertSee('MULTI');

    expect(FakeTempoVerifier::$calls)->toBe(1)
        ->and(FakeVerifier::$calls)->toBe(0)
        ->and(decodeReceipt($response->headers->get('Payment-Receipt'))['method'])->toBe('tempo');
});

it('dispatches by the STORED challenge method, not the credential claim', function () {
    // Echo the stripe challenge's id but claim method=tempo in the echo: the
    // stored challenge wins, so the stripe verifier runs, sees no SPT in the
    // payload, and settlement fails with a fresh 402 — never the tempo rail.
    $challenge = getChallenge($this, '/multi', 'stripe');
    $challenge['method'] = 'tempo';

    $this->withHeaders([
        'Authorization' => paymentCredential($challenge, ['type' => 'transaction', 'signature' => '0x76abc']),
    ])->get('/multi')->assertStatus(402);

    expect(FakeTempoVerifier::$calls)->toBe(0);
});

it('burns only the answered challenge; the sibling stays spendable', function () {
    $response = $this->get('/multi');
    $challenges = array_column(challengesFrom($response), null, 'method');

    $this->withHeaders([
        'Authorization' => paymentCredential($challenges['stripe'], ['spt' => 'spt_x']),
    ])->get('/multi')->assertOk();

    // The tempo challenge from the SAME 402 was not answered and remains valid.
    $this->withHeaders([
        'Authorization' => paymentCredential($challenges['tempo'], ['type' => 'transaction', 'signature' => '0x76abc']),
    ])->get('/multi')->assertOk();

    expect(FakeVerifier::$calls)->toBe(1)->and(FakeTempoVerifier::$calls)->toBe(1);
});

it('rejects a credential answering an unknown challenge id', function () {
    $challenge = getChallenge($this, '/multi', 'stripe');
    $challenge['id'] = 'never-minted-'.$challenge['id'];

    $this->withHeaders([
        'Authorization' => paymentCredential($challenge, ['spt' => 'spt_x']),
    ])->get('/multi')->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(0);
});
