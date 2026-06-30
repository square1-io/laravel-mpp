<?php

use Square1\Mpp\Tests\Fakes\FakeTempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeVerifier;
use Square1\Mpp\Tests\TestCase;

beforeEach(function () {
    FakeVerifier::reset();
    FakeTempoVerifier::reset();
});

/**
 * Fetch the /multi 402 and return its challenge id + per-method accepts keyed by
 * method name.
 *
 * @return array{id:string, accepts: array<string, array<string,mixed>>}
 */
function multiChallenge(TestCase $test): array
{
    $response = $test->get('/multi');
    $accepts = [];
    foreach ((array) $response->json('accepts') as $accept) {
        $accepts[$accept['method']] = $accept;
    }

    return ['id' => (string) $response->json('challengeId'), 'accepts' => $accepts];
}

function payWithProof(TestCase $test, string $method, string $challengeId, string $sig, string $proofAttr, string $proof)
{
    return $test->withHeaders([
        'Authorization' => sprintf(
            'Payment method="%s", challengeId="%s", sig="%s", %s="%s"',
            $method,
            $challengeId,
            $sig,
            $proofAttr,
            $proof,
        ),
    ])->get('/multi');
}

it('offers both methods in the 402', function () {
    $challenge = multiChallenge($this);

    expect($challenge['accepts'])->toHaveKeys(['stripe', 'tempo'])
        ->and($challenge['accepts']['stripe']['sig'])->not->toBe($challenge['accepts']['tempo']['sig'])
        ->and($challenge['accepts']['tempo']['payment_method_types'])->toBe(['stablecoin']);
});

it('routes a stripe credential to the stripe verifier', function () {
    $c = multiChallenge($this);

    $response = payWithProof($this, 'stripe', $c['id'], $c['accepts']['stripe']['sig'], 'spt', 'spt_x');

    $response->assertOk()->assertSee('MULTI');
    expect(FakeVerifier::$calls)->toBe(1)
        ->and(FakeTempoVerifier::$calls)->toBe(0)
        ->and($response->headers->get('Payment-Receipt'))->toContain('method="stripe"');
});

it('routes a tempo credential to the tempo verifier', function () {
    $c = multiChallenge($this);

    $response = payWithProof($this, 'tempo', $c['id'], $c['accepts']['tempo']['sig'], 'proof', '0xabc');

    $response->assertOk()->assertSee('MULTI');
    expect(FakeTempoVerifier::$calls)->toBe(1)
        ->and(FakeVerifier::$calls)->toBe(0)
        ->and($response->headers->get('Payment-Receipt'))
        ->toContain('method="tempo"')
        ->toContain('ref="0xtempo_1"')
        ->not->toContain('paymentIntent=');
});

it('rejects a method that was not offered', function () {
    $c = multiChallenge($this);

    // Sign-looking but for an un-offered method.
    payWithProof($this, 'paypal', $c['id'], $c['accepts']['stripe']['sig'], 'proof', 'x')
        ->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(0)->and(FakeTempoVerifier::$calls)->toBe(0);
});

it('rejects a stripe signature presented on a tempo credential (cross-method forgery)', function () {
    $c = multiChallenge($this);

    // Present method=tempo but with the STRIPE accept's signature.
    payWithProof($this, 'tempo', $c['id'], $c['accepts']['stripe']['sig'], 'proof', '0xabc')
        ->assertStatus(402);

    expect(FakeTempoVerifier::$calls)->toBe(0);
});

it('rejects a tempo signature presented on a stripe credential (cross-method forgery)', function () {
    $c = multiChallenge($this);

    payWithProof($this, 'stripe', $c['id'], $c['accepts']['tempo']['sig'], 'spt', 'spt_x')
        ->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(0);
});
