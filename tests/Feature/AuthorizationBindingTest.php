<?php

use Illuminate\Support\Facades\Route;
use Square1\Mpp\Tests\Fakes\FakeTempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeVerifier;

/**
 * A paid challenge authorises ONE resource: the scope it was minted for.
 *
 * The binding proves the challenge's terms are ours; only the scope proves they
 * are THIS route's terms. Both settlement paths must check it — the paid path
 * (pay a cheap challenge, ask for an expensive route) and the replay path (pay
 * once, then present the settled id to every other guarded route for free).
 *
 * `/clip` is 0.50 (scope `clip`); `/report` is 5.00 with grants=10 (scope
 * `report.basic`).
 */
beforeEach(function () {
    FakeVerifier::reset();
    FakeTempoVerifier::reset();
});

it('does not replay a settled credential against a different route', function () {
    $challenge = getChallenge($this, '/clip');

    payWithSpt($this, $challenge, '/clip')->assertOk()->assertSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);

    // Same settled credential, more expensive route: the ledger holds a receipt
    // for this id, but it was earned on `clip`. Replaying it here would serve
    // /report for free.
    $crossRoute = payWithSpt($this, $challenge, '/report');

    $crossRoute->assertStatus(402)
        ->assertHeader('WWW-Authenticate')
        ->assertJsonPath('status', 402);

    expect($crossRoute->headers->get('Payment-Receipt'))->toBeNull()
        ->and($crossRoute->headers->get('Payment-Session'))->toBeNull()
        // No second settlement was attempted for /report either.
        ->and(FakeVerifier::$calls)->toBe(1);
});

it('does not settle a challenge minted for another route', function () {
    // The paid path: an unspent /clip challenge (0.50) answered at /report
    // (5.00). Paying 50c must not unlock the $5 resource.
    $challenge = getChallenge($this, '/clip');

    $response = payWithSpt($this, $challenge, '/report');

    $response->assertStatus(402)->assertJsonPath('detail', 'This challenge was not issued for this resource. A new challenge has been issued.');

    expect($response->headers->get('Payment-Receipt'))->toBeNull()
        ->and($response->headers->get('Payment-Session'))->toBeNull()
        // Rejected before the verifier: no charge is taken for a challenge the
        // route would not honour.
        ->and(FakeVerifier::$calls)->toBe(0);

    // And the /clip challenge is untouched by the failed attempt — it is still
    // spendable on the route it was minted for.
    payWithSpt($this, $challenge, '/clip')->assertOk()->assertSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);
});

it('still replays a settled receipt on the route that paid for it', function () {
    // The legitimate case the scope check must not break: a lost 200, retried
    // against the SAME route, replays the original receipt.
    $challenge = getChallenge($this, '/clip');

    $first = payWithSpt($this, $challenge, '/clip')->assertOk()->assertSee('CLIP');
    $replay = payWithSpt($this, $challenge, '/clip')->assertOk()->assertSee('CLIP');

    $ref = fn ($r) => decodeReceipt($r->headers->get('Payment-Receipt'))['reference'];

    expect($ref($replay))->toBe($ref($first))
        ->and(FakeVerifier::$calls)->toBe(1);
});

it('does not let a metered session bought on one route replay on another', function () {
    // A metered route settles and hands back a session. Presenting the settled
    // CHALLENGE elsewhere must not replay that session onto another route
    // (the session credential path is already scope-checked in consume()).
    $challenge = getChallenge($this, '/report');

    $paid = payWithSpt($this, $challenge, '/report')->assertOk();
    expect($paid->headers->get('Payment-Session'))->toContain('remaining="9"');

    $crossRoute = payWithSpt($this, $challenge, '/clip');

    $crossRoute->assertStatus(402)->assertDontSee('CLIP');
    expect($crossRoute->headers->get('Payment-Session'))->toBeNull()
        ->and(FakeVerifier::$calls)->toBe(1);

    // The session itself still has its ten credits for its own route.
    spendWithSession($this, sessionIdFromHeader($paid->headers->get('Payment-Session')), '/report')->assertOk();
});

it('does not settle a rail the route no longer offers', function () {
    // `/multi` offers stripe + tempo. Mint a tempo challenge, then narrow the
    // route's offer to stripe only: the still-live tempo challenge must not
    // settle, because tempo is no longer an accepted rail for this resource.
    $challenge = getChallenge($this, '/multi', 'tempo');

    Route::get('/multi', fn () => response('MULTI', 200))
        ->middleware('mpp:0.50,USD,methods=stripe,scope=multi.clip');

    $response = $this->withHeaders([
        'Authorization' => paymentCredential($challenge, ['type' => 'transaction', 'signature' => '0x76abc']),
    ])->get('/multi');

    $response->assertStatus(402)->assertDontSee('MULTI');
    expect(FakeTempoVerifier::$calls)->toBe(0);
});
