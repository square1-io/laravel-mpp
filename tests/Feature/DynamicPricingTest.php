<?php

use Illuminate\Support\Facades\Log;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Tests\Fakes\AllowPrecondition;
use Square1\Mpp\Tests\Fakes\DenyPrecondition;
use Square1\Mpp\Tests\Fakes\FakeTempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeVerifier;
use Square1\Mpp\Tests\Fakes\MalformedPricing;
use Square1\Mpp\Tests\Fakes\RegionPricing;
use Square1\Mpp\Tests\Fakes\TieredPricing;

beforeEach(function () {
    TieredPricing::reset();
    RegionPricing::reset();
    AllowPrecondition::reset();
    DenyPrecondition::reset();
    FakeVerifier::reset();
    FakeTempoVerifier::reset();
});

/** Pull the minor-unit amount out of an mppx (tempo) 402's request blob. */
function mppxChallengedAmount($response): ?string
{
    $challenge = parseChallenges($response->headers->get('WWW-Authenticate'))[0] ?? [];
    $request = json_decode((string) Base64Url::decode($challenge['request'] ?? ''), true);

    return is_array($request) ? ($request['amount'] ?? null) : null;
}

// ── Overriding the price ────────────────────────────────────────────────────

it('mints the 402 at the resolver amount instead of the route amount', function () {
    TieredPricing::$overrides = ['amount' => '2.00'];

    $response = $this->get('/price/tiered')->assertStatus(402);

    expect(challengedAmount($response))->toBe('2.00')
        ->and(TieredPricing::$calls)->toBe(1);
});

it('charges two callers different prices on the same route', function () {
    TieredPricing::$using = fn ($request) => $request->header('X-Tier') === 'pro'
        ? ['amount' => '2.00']
        : ['amount' => '5.00'];

    $pro = $this->withHeader('X-Tier', 'pro')->get('/price/tiered');
    $standard = $this->flushHeaders()->get('/price/tiered');

    expect(challengedAmount($pro))->toBe('2.00')
        ->and(challengedAmount($standard))->toBe('5.00');
});

it('keeps the route amount when the resolver declines', function () {
    TieredPricing::$overrides = null;

    $response = $this->get('/price/tiered')->assertStatus(402);

    expect(challengedAmount($response))->toBe('5.00')
        ->and(TieredPricing::$calls)->toBe(1);
});

it('keeps the route amount when the resolver returns an empty array', function () {
    TieredPricing::$overrides = [];

    expect(challengedAmount($this->get('/price/tiered')))->toBe('5.00');
});

it('overrides currency, grants and scope alongside the amount', function () {
    TieredPricing::$overrides = [
        'amount' => '12.00',
        'currency' => 'eur',
        'grants' => 20,
        'scope' => 'price.tiered.pro',
    ];

    $response = $this->get('/price/tiered')->assertStatus(402);

    $challenge = parseChallenges($response->headers->get('WWW-Authenticate'))[0];
    $request = json_decode((string) Base64Url::decode($challenge['request']), true);

    expect($request['amount'])->toBe('1200')
        ->and($request['currency'])->toBe('eur')
        ->and(challengedOpaque($response, 'grants'))->toBe('20')
        ->and(challengedOpaque($response, 'scope'))->toBe('price.tiered.pro');
});

it('does not let a resolver change the offered rail', function () {
    TieredPricing::$overrides = ['method' => 'tempo'];

    $this->withoutExceptionHandling()->get('/price/tiered');
})->throws(InvalidConfigurationException::class, 'unknown override(s): method');

it('prices a route reached through a price_book key', function () {
    TieredPricing::$overrides = ['amount' => '3.00'];

    expect(challengedAmount($this->get('/price/book')))->toBe('3.00');
});

it('prices the mppx (tempo) rail too', function () {
    TieredPricing::$overrides = ['amount' => '0.02'];

    $response = $this->get('/price/tempo')->assertStatus(402);

    expect(mppxChallengedAmount($response))->toBe('20000') // 0.02 pathUSD at 6 decimals
        ->and(TieredPricing::$calls)->toBe(1);
});

// ── Composition and ordering ────────────────────────────────────────────────

it('applies a global resolver to a route that declares none', function () {
    config()->set('mpp.pricing.global', ['tiered']);
    TieredPricing::$overrides = ['amount' => '2.00'];

    expect(challengedAmount($this->get('/price/open')))->toBe('2.00');
});

it('runs no resolver at all on a route with none declared', function () {
    expect(challengedAmount($this->get('/price/open')))->toBe('5.00')
        ->and(TieredPricing::$calls)->toBe(0)
        ->and(RegionPricing::$calls)->toBe(0);
});

it('runs globals first, then the route’s own, each seeing the last result', function () {
    config()->set('mpp.pricing.global', ['region']);
    RegionPricing::$overrides = ['amount' => '8.00'];
    TieredPricing::$overrides = ['amount' => '2.00'];

    $response = $this->get('/price/tiered');

    // The global saw the static price; the route's own saw the global's result;
    // the last to speak wins.
    expect(RegionPricing::$sawAmounts)->toBe(['5.00'])
        ->and(TieredPricing::$sawAmounts)->toBe(['8.00'])
        ->and(challengedAmount($response))->toBe('2.00');
});

it('composes two route resolvers in declared order', function () {
    TieredPricing::$overrides = ['amount' => '2.00'];
    RegionPricing::$using = fn ($request, $spec) => ['amount' => bcmul($spec->amount, '2', 2)];

    $response = $this->get('/price/both');

    expect(TieredPricing::$sawAmounts)->toBe(['5.00'])
        ->and(RegionPricing::$sawAmounts)->toBe(['2.00'])
        ->and(challengedAmount($response))->toBe('4.00');
});

it('de-duplicates a resolver listed both globally and on the route', function () {
    config()->set('mpp.pricing.global', ['tiered']);
    TieredPricing::$overrides = ['amount' => '2.00'];

    $this->get('/price/tiered')->assertStatus(402);

    expect(TieredPricing::$calls)->toBe(1);
});

it('throws for an unknown price resolver name', function () {
    $this->withoutExceptionHandling();

    $this->get('/price/unknown');
})->throws(InvalidConfigurationException::class, "Unknown price resolver 'ghost'");

it('throws when a resolver returns a non-array, non-null value', function () {
    config()->set('mpp.pricing.resolvers.tiered', [MalformedPricing::class, 'price']);

    $this->withoutExceptionHandling()->get('/price/tiered');
})->throws(InvalidConfigurationException::class, "The price resolver 'tiered' must return an array");

// ── free => true ────────────────────────────────────────────────────────────

it('serves the resource without charging when a resolver returns free', function () {
    TieredPricing::$overrides = ['free' => true];

    $response = $this->get('/price/tiered');

    $response->assertOk()->assertSee('TIERED');
    expect($response->headers->get('WWW-Authenticate'))->toBeNull()
        ->and($response->headers->get('Payment-Receipt'))->toBeNull()
        ->and($response->headers->get('Payment-Session'))->toBeNull()
        ->and(FakeVerifier::$calls)->toBe(0);
});

it('waives the charge on a metered route without issuing a session', function () {
    TieredPricing::$overrides = ['free' => true];

    $response = $this->get('/price/metered');

    $response->assertOk();
    expect($response->headers->get('Payment-Session'))->toBeNull();
});

it('waives the charge on the mppx (tempo) rail too', function () {
    TieredPricing::$overrides = ['free' => true];

    $this->get('/price/tempo')->assertOk()->assertSee('TEMPO');
});

it('treats free => false as a normal priced request', function () {
    TieredPricing::$overrides = ['free' => false, 'amount' => '2.00'];

    expect(challengedAmount($this->get('/price/tiered')))->toBe('2.00');
});

it('still runs preconditions on a request the resolver made free', function () {
    config()->set('mpp.preconditions.global', ['deny']);
    TieredPricing::$overrides = ['free' => true];

    $this->get('/price/tiered')->assertStatus(404)->assertDontSee('TIERED');

    expect(DenyPrecondition::$calls)->toBe(1);
});

it('refuses a zero amount, insisting free be stated explicitly', function () {
    TieredPricing::$overrides = ['amount' => '0'];

    $this->withoutExceptionHandling()->get('/price/tiered');
})->throws(InvalidConfigurationException::class, "to waive the charge return `'free' => true` instead");

it('refuses free and an amount together', function () {
    TieredPricing::$overrides = ['free' => true, 'amount' => '2.00'];

    $this->withoutExceptionHandling()->get('/price/tiered');
})->throws(InvalidConfigurationException::class, "returned both 'free' => true and an 'amount'");

// ── Interaction with preconditions ──────────────────────────────────────────

it('hands preconditions the resolved price, not the route’s static one', function () {
    TieredPricing::$overrides = ['amount' => '2.00'];

    $this->get('/price/precond')->assertStatus(402);

    expect(AllowPrecondition::$sawAmounts)->toBe(['2.00']);
});

// ── Settlement is bound to the minted challenge ─────────────────────────────

it('settles at the challenged amount even after the resolver changes its answer', function () {
    TieredPricing::$overrides = ['amount' => '2.00'];
    $challenge = getChallenge($this, '/price/tiered');

    // The buyer's tier (or the resolver's logic) changes between the 402 and the
    // paid retry. The quote they were given has to stand.
    TieredPricing::$overrides = ['amount' => '9.00'];

    $response = payWithSpt($this, $challenge, '/price/tiered');

    $response->assertOk()->assertSee('TIERED');
    expect(decodeReceipt($response->headers->get('Payment-Receipt'))['amount'])->toBe('2.00');
});

it('cannot be turned free retroactively to settle a challenge for nothing', function () {
    TieredPricing::$overrides = ['amount' => '2.00'];
    $challenge = getChallenge($this, '/price/tiered');

    // A resolver flipping to free after the 402 serves the resource free — it
    // never reaches settlement — but the unspent challenge is left intact rather
    // than being burned by a payment that did not happen.
    TieredPricing::$overrides = ['free' => true];
    payWithSpt($this, $challenge, '/price/tiered')->assertOk();
    expect(FakeVerifier::$calls)->toBe(0);

    // Back to a price: the original challenge is still good for exactly one
    // settlement, at the amount it was minted with.
    TieredPricing::$overrides = ['amount' => '2.00'];
    $response = payWithSpt($this, $challenge, '/price/tiered');

    $response->assertOk();
    expect(decodeReceipt($response->headers->get('Payment-Receipt'))['amount'])->toBe('2.00');
});

it('spends a metered session at the scope the resolver assigned', function () {
    TieredPricing::$overrides = ['amount' => '2.00', 'grants' => 4, 'scope' => 'price.metered.pro'];

    $paid = payWithSpt($this, getChallenge($this, '/price/metered'), '/price/metered');
    $paid->assertOk();

    $session = sessionIdFromHeader($paid->headers->get('Payment-Session'));
    expect($session)->not->toBe('')
        ->and($paid->headers->get('Payment-Session'))->toContain('scope="price.metered.pro"')
        ->and($paid->headers->get('Payment-Session'))->toContain('remaining="3"');

    $spent = spendWithSession($this, $session, '/price/metered');
    $spent->assertOk();
    expect($spent->headers->get('Payment-Session'))->toContain('remaining="2"');
});

// ── The metered-scope warning ──────────────────────────────────────────────

it('warns when a resolver reprices a metered route without changing its scope', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, "metered scope 'price.metered'")
            && str_contains($message, 'scope-bound, not payer-bound'));

    TieredPricing::$overrides = ['amount' => '2.00'];

    $this->get('/price/metered')->assertStatus(402);
});

it('does not warn when the resolver gives the repriced tier its own scope', function () {
    Log::shouldReceive('warning')->never();

    TieredPricing::$overrides = ['amount' => '2.00', 'scope' => 'price.metered.pro'];

    $this->get('/price/metered')->assertStatus(402);
});

it('does not warn on a once-off route', function () {
    Log::shouldReceive('warning')->never();

    TieredPricing::$overrides = ['amount' => '2.00'];

    $this->get('/price/tiered')->assertStatus(402);
});

it('warns only once per scope per process', function () {
    Log::shouldReceive('warning')->once();

    TieredPricing::$overrides = ['amount' => '2.00'];

    $this->get('/price/metered')->assertStatus(402);
    $this->get('/price/metered')->assertStatus(402);
});

// ── Attribute parity ───────────────────────────────────────────────────────

it('prices a route from its #[RequiresPayment] pricing list', function () {
    TieredPricing::$overrides = ['amount' => '2.00'];

    expect(challengedAmount($this->get('/attr/tiered')))->toBe('2.00');
});

it('waives the charge on an attribute-priced route', function () {
    TieredPricing::$overrides = ['free' => true];

    $this->get('/attr/tiered')->assertOk()->assertSee('TIERED');
});

it('runs the preconditions declared on a #[RequiresPayment] attribute', function () {
    $this->get('/attr/guarded')
        ->assertStatus(404)
        ->assertJson(['error' => 'precondition failed']);

    expect(DenyPrecondition::$calls)->toBe(1);
});
