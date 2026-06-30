<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Tests\Fakes\AllowPrecondition;
use Square1\Mpp\Tests\Fakes\DenyPrecondition;
use Square1\Mpp\Tests\Fakes\FakeVerifier;

beforeEach(function () {
    AllowPrecondition::reset();
    DenyPrecondition::reset();
    FakeVerifier::reset();
});

it('mints the 402 as usual when a route precondition passes', function () {
    $this->get('/precond/allow')->assertStatus(402);

    expect(AllowPrecondition::$calls)->toBe(1);
});

it('returns the precondition response instead of a 402 when it rejects', function () {
    $this->get('/precond/deny')
        ->assertStatus(404)
        ->assertJson(['error' => 'precondition failed']);

    expect(DenyPrecondition::$calls)->toBe(1);
});

it('never reaches settlement when a precondition rejects a paid retry', function () {
    $this->withHeaders([
        'Authorization' => 'Payment method="stripe", challengeId="chal_x", sig="x", spt="spt_x"',
    ])->get('/precond/deny')->assertStatus(404);

    expect(FakeVerifier::$calls)->toBe(0);
});

it('runs global preconditions on a route that declares none of its own', function () {
    config()->set('mpp.preconditions.global', ['deny']);

    $this->get('/precond/open')->assertStatus(404);

    expect(DenyPrecondition::$calls)->toBe(1);
});

it('runs globals first and short-circuits before route-specific checks', function () {
    config()->set('mpp.preconditions.global', ['deny']);

    // The route adds `allow`, but the global `deny` runs first and wins.
    $this->get('/precond/allow')->assertStatus(404);

    expect(DenyPrecondition::$calls)->toBe(1)
        ->and(AllowPrecondition::$calls)->toBe(0);
});

it('runs route checks after a passing global, in order', function () {
    config()->set('mpp.preconditions.global', ['allow']);

    $this->get('/precond/deny')->assertStatus(404);

    expect(AllowPrecondition::$calls)->toBe(1)
        ->and(DenyPrecondition::$calls)->toBe(1);
});

it('de-duplicates a check listed both globally and on the route', function () {
    config()->set('mpp.preconditions.global', ['allow']);

    $this->get('/precond/allow')->assertStatus(402);

    expect(AllowPrecondition::$calls)->toBe(1);
});

it('throws for an unknown precondition name', function () {
    $this->withoutExceptionHandling();

    $this->get('/precond/unknown');
})->throws(InvalidConfigurationException::class, "Unknown precondition 'ghost'");
