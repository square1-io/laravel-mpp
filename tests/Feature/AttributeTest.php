<?php

use Square1\Mpp\Tests\Fakes\FakeVerifier;

beforeEach(fn () => FakeVerifier::reset());

it('challenges an attributed action via the explicit mpp middleware', function () {
    $this->get('/attr/explicit')
        ->assertStatus(402)
        ->assertJsonPath('accepts.0.amount', '0.50')
        ->assertJsonPath('accepts.0.grants', 1);
});

it('settles an attributed action reached via the explicit mpp middleware', function () {
    payWithSpt($this, getChallenge($this, '/attr/explicit'), '/attr/explicit')->assertOk()->assertSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);
});

it('auto-enforces the attribute via the EnforcePaymentAttributes middleware', function () {
    $this->get('/attr/auto')->assertStatus(402)->assertJsonPath('accepts.0.amount', '0.50');

    payWithSpt($this, getChallenge($this, '/attr/auto'), '/attr/auto')->assertOk()->assertSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);
});

it('auto-enforces a metered attributed action with its grants and scope', function () {
    $this->get('/attr/report')
        ->assertStatus(402)
        ->assertJsonPath('accepts.0.amount', '5.00')
        ->assertJsonPath('accepts.0.grants', 10)
        ->assertJsonPath('accepts.0.scope', 'report.basic');
});

it('passes through routes without the attribute', function () {
    $this->get('/attr/plain')->assertOk()->assertSee('FREE');
    expect(FakeVerifier::$calls)->toBe(0);
});
