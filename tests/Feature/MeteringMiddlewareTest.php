<?php

use Square1\Mpp\Tests\Fakes\FakeVerifier;

beforeEach(fn () => FakeVerifier::reset());

it('issues a session with remaining credits on a metered payment', function () {
    $paid = payWithSpt($this, getChallenge($this, '/report'), '/report');

    $paid->assertOk();
    expect($paid->headers->get('Payment-Receipt'))->not->toBeNull();
    expect($paid->headers->get('Payment-Session'))
        ->not->toBeNull()
        ->toContain('remaining="9"')   // 1 of 10 spent
        ->toContain('scope="report.basic"');
});

it('serves ten accesses for one charge then re-challenges', function () {
    $paid = payWithSpt($this, getChallenge($this, '/report'), '/report');
    $sessionId = sessionIdFromHeader($paid->headers->get('Payment-Session'));

    expect($sessionId)->not->toBeEmpty();

    for ($remaining = 8; $remaining >= 0; $remaining--) {
        spendWithSession($this, $sessionId, '/report')
            ->assertOk()
            ->assertHeader('Payment-Session');
    }

    spendWithSession($this, $sessionId, '/report')->assertStatus(402);
    expect(FakeVerifier::$calls)->toBe(1);
});

it('cannot spend a session on a different scope', function () {
    $sessionId = sessionIdFromHeader(
        payWithSpt($this, getChallenge($this, '/report'), '/report')->headers->get('Payment-Session')
    );

    spendWithSession($this, $sessionId, '/clip')->assertStatus(402);
});

it('issues no session for a once-off endpoint', function () {
    $response = payWithSpt($this, getChallenge($this, '/clip'), '/clip');

    $response->assertOk();
    expect($response->headers->get('Payment-Session'))->toBeNull();
});
