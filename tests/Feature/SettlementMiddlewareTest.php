<?php

use Illuminate\Contracts\Cache\Factory;
use Square1\Mpp\Tests\Fakes\FakeVerifier;
use Square1\Mpp\Tests\Fakes\SideEffectCounter;

beforeEach(fn () => FakeVerifier::reset());

it('settles a paid retry and returns 200 with a receipt', function () {
    $response = payWithSpt($this, getChallenge($this, '/clip'), '/clip');

    $response->assertOk()->assertSee('CLIP');
    $receipt = decodeReceipt($response->headers->get('Payment-Receipt'));

    expect($receipt['status'])->toBe('success')
        ->and($receipt['reference'])->toBe('pi_fake_1')
        ->and($receipt)->not->toHaveKeys(['amount', 'currency']);
    expect(FakeVerifier::$calls)->toBe(1);
});

it('replays the receipt for a settled challenge instead of charging again', function () {
    // A retry of an already-settled payment (e.g. the original 200 was lost in
    // transit) must replay the original receipt with a 200 — never a fresh 402,
    // which would invite a second payment — and must not settle a second time.
    $challenge = getChallenge($this, '/clip');

    $first = payWithSpt($this, $challenge, '/clip')->assertOk()->assertSee('CLIP');
    $second = payWithSpt($this, $challenge, '/clip')->assertOk()->assertSee('CLIP');
    $third = payWithSpt($this, $challenge, '/clip')->assertOk();

    $ref = fn ($r) => decodeReceipt($r->headers->get('Payment-Receipt'))['reference'];

    expect($ref($second))->toBe($ref($first))
        ->and($ref($third))->toBe($ref($first))
        ->and(FakeVerifier::$calls)->toBe(1);
});

it('a retried metered payment reissues the same session, not a second one', function () {
    // Replaying a settled metered challenge must hand back the SAME session and
    // spend no extra credit — one payment, one N-credit session, whatever the
    // client's retry count.
    $challenge = getChallenge($this, '/report');

    $first = payWithSpt($this, $challenge, '/report')->assertOk();
    $replay = payWithSpt($this, $challenge, '/report')->assertOk();

    expect(sessionIdFromHeader($replay->headers->get('Payment-Session')))
        ->toBe(sessionIdFromHeader($first->headers->get('Payment-Session')))
        // The first paid request spent credit 1 (10 -> 9); the replay re-reads
        // the live session and spends nothing, so it still reads 9.
        ->and($first->headers->get('Payment-Session'))->toContain('remaining="9"')
        ->and($replay->headers->get('Payment-Session'))->toContain('remaining="9"')
        ->and(FakeVerifier::$calls)->toBe(1);
});

it('replays the stored response without re-running the protected action', function () {
    SideEffectCounter::reset();
    $challenge = getChallenge($this, '/sideeffect');

    $first = payWithSpt($this, $challenge, '/sideeffect')->assertOk();
    $replay = payWithSpt($this, $challenge, '/sideeffect')->assertOk();

    // The action ran exactly once (at settlement). The replay returned the
    // stored response verbatim instead of executing the action a second time.
    expect(SideEffectCounter::$hits)->toBe(1)
        ->and($first->getContent())->toBe('{"hits":1}')
        ->and($replay->getContent())->toBe('{"hits":1}')
        ->and(FakeVerifier::$calls)->toBe(1);
});

it('answers a concurrent in-flight settlement with 409, not a fresh 402', function () {
    // If the settlement lock is already held (another request is settling this
    // same challenge), a second arrival must be told to WAIT (409 + Retry-After),
    // never handed a 402 that reads as "pay again".
    $challenge = getChallenge($this, '/clip');
    $id = $challenge['id'];

    // Simulate the in-flight settlement by holding the lock this request needs.
    $lock = $this->app->make(Factory::class)
        ->store()->lock('mpp:settle:'.$id, 10);
    expect($lock->get())->toBeTrue();

    try {
        $response = payWithSpt($this, $challenge, '/clip');
    } finally {
        $lock->release();
    }

    $response->assertStatus(409)
        ->assertHeader('Retry-After', '2')
        ->assertJsonPath('challengeId', $id);
    expect(FakeVerifier::$calls)->toBe(0);
});

it('re-challenges an unknown challenge without settling', function () {
    payWithSpt($this, ['id' => 'chal_nope', 'sig' => 'x'], '/clip')->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(0);
});

it('refuses a tampered signature', function () {
    $challenge = getChallenge($this, '/clip');
    // Tampering now means altering any bound field: the id IS the signature.
    $challenge['id'] = rtrim(strrev($challenge['id']), '=');

    payWithSpt($this, $challenge, '/clip')->assertStatus(402)->assertDontSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(0);
});

it('does not serve when settlement is declined', function () {
    FakeVerifier::$succeed = false;

    payWithSpt($this, getChallenge($this, '/clip'), '/clip')->assertStatus(402)->assertDontSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);
});

it('passes a resolved customer to the verifier', function () {
    config()->set('mpp.methods.stripe.customer_resolver', fn ($request) => 'cus_demo');

    payWithSpt($this, getChallenge($this, '/clip'), '/clip')->assertOk();

    expect(FakeVerifier::$lastContext['customer'] ?? null)->toBe('cus_demo');
});
