<?php

use Square1\Mpp\Tests\Fakes\FakeVerifier;

beforeEach(fn () => FakeVerifier::reset());

it('settles a paid retry and returns 200 with a receipt', function () {
    $response = payWithSpt($this, getChallenge($this, '/clip'), '/clip');

    $response->assertOk()->assertSee('CLIP');
    expect($response->headers->get('Payment-Receipt'))
        ->not->toBeNull()
        ->toContain('ref="pi_fake_1"')
        ->toContain('amount="0.50"');
    expect(FakeVerifier::$calls)->toBe(1);
});

it('re-challenges a burned challenge without settling again', function () {
    $challenge = getChallenge($this, '/clip');

    payWithSpt($this, $challenge, '/clip')->assertOk();
    payWithSpt($this, $challenge, '/clip')->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(1);
});

it('re-challenges an unknown challenge without settling', function () {
    payWithSpt($this, ['id' => 'chal_nope', 'sig' => 'x'], '/clip')->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(0);
});

it('refuses a tampered signature', function () {
    $challenge = getChallenge($this, '/clip');
    $challenge['sig'] = str_repeat('0', strlen($challenge['sig']));

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
