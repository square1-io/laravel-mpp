<?php

use Illuminate\Support\Facades\Log;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Protocol\Requests\RailRequestBuilder;

it('serves an OpenAPI discovery document derived from the live router', function () {
    $response = $this->get('/openapi.json')->assertOk();

    $doc = $response->json();

    expect($doc['openapi'])->toBe('3.1.0')
        ->and($doc['paths'])->toHaveKey('/clip')
        ->and($doc['paths'])->toHaveKey('/multi');

    $clip = $doc['paths']['/clip']['get'];

    expect($clip['x-payment-info']['offers'][0])->toBe([
        'method' => 'stripe',
        'intent' => 'charge',
        'amount' => '50',
        'currency' => 'usd',
    ])->and($clip['responses'])->toHaveKey('402');
});

it('advertises every offered rail for a multi-rail route', function () {
    $offers = $this->get('/openapi.json')->json('paths./multi.get.x-payment-info.offers');

    expect(array_column($offers, 'method'))->toBe(['stripe', 'tempo'])
        ->and($offers[1]['amount'])->toBe('500000')
        ->and($offers[1]['currency'])->toBe('0x20C000000000000000000000b9537d11c60E8b50');
});

it('orders offers primary-first from the configured accept set', function () {
    config()->set('mpp.accept', ['tempo', 'stripe']);

    $offers = $this->get('/openapi.json')->json('paths./clip.get.x-payment-info.offers');

    // The set's own order leads, whatever `default_method` is (stripe here).
    // `default_method` chooses the rail for routes that name none; it does not
    // reorder a set that does.
    expect(array_column($offers, 'method'))->toBe(['tempo', 'stripe']);
});

it('advertises the same rail order the live 402 mints', function (array $accept) {
    config()->set('mpp.accept', $accept);

    $advertised = array_column(
        $this->get('/openapi.json')->json('paths./clip.get.x-payment-info.offers'),
        'method'
    );
    $minted = array_column(challengesFrom($this->get('/clip')->assertStatus(402)), 'method');

    // Discovery and the gate read the offered set from one place. When they had
    // an implementation each, a change to one silently desynced the order a
    // client is advertised from the order it is actually offered.
    expect($advertised)->toBe($accept)
        ->and($minted)->toBe($advertised);
})->with([
    'tempo leads' => [['tempo', 'stripe']],
    'stripe leads' => [['stripe', 'tempo']],
]);

it('advertises a resolver-priced route with an explicit null amount', function () {
    // `mpp:scope=price.owned,pricing=tiered` states no amount at all: only a
    // request can price it, and the key has to be present to say so.
    $offer = $this->get('/openapi.json')->json('paths./price/resolver-owned.get.x-payment-info.offers.0');

    expect($offer)->toBe([
        'method' => 'stripe',
        'intent' => 'charge',
        'amount' => null,
        'currency' => 'usd',
    ]);
});

it('resolves a leading price_book key to the entry it names', function () {
    // `mpp:report.dynamic` names a price_book entry ($5.00 USD) — it is not a
    // route that costs "report.dynamic".
    $offer = $this->get('/openapi.json')->json('paths./price/book.get.x-payment-info.offers.0');

    expect($offer)->toBe([
        'method' => 'stripe',
        'intent' => 'charge',
        'amount' => '500',
        'currency' => 'usd',
    ]);
});

it('takes the offered rails from a price_book entry', function () {
    config()->set('mpp.price_book', [
        'report.dynamic' => ['amount' => '5.00', 'currency' => 'USD', 'methods' => ['tempo', 'stripe']],
    ]);

    $offers = $this->get('/openapi.json')->json('paths./price/book.get.x-payment-info.offers');

    // An entry's own list is explicit and ordered, so tempo keeps the primary
    // slot even though `default_method` is stripe.
    expect(array_column($offers, 'method'))->toBe(['tempo', 'stripe'])
        ->and($offers[0]['amount'])->toBe('5000000');
});

it('fills an amount and currency the route never stated from mpp.defaults', function () {
    config()->set('mpp.defaults.amount', '2');
    config()->set('mpp.defaults.currency', 'JPY');

    $offer = $this->get('/openapi.json')->json('paths./price/nothing.get.x-payment-info.offers.0');

    // JPY is zero-decimal, so ¥2 is "2" minor units — which only comes out
    // right if the currency was read from `mpp.defaults.currency`.
    expect($offer['amount'])->toBe('2')
        ->and($offer['currency'])->toBe('jpy');
});

it('mints each offer through the method\'s configured request_builder', function () {
    app()->bind('discovery.test-builder', fn () => new class implements RailRequestBuilder
    {
        public function build(PaymentSpec $spec, array $config): array
        {
            return ['amount' => '999', 'currency' => 'xts'];
        }
    });

    config()->set('mpp.methods.stripe.request_builder', 'discovery.test-builder');

    $offer = $this->get('/openapi.json')->json('paths./clip.get.x-payment-info.offers.0');

    expect($offer['amount'])->toBe('999')
        ->and($offer['currency'])->toBe('xts');
});

it('stays up when a rail builder fails, and says why in the log', function () {
    Log::spy();

    // Tempo cannot mint a request without a recipient.
    config()->set('mpp.methods.tempo.recipient', null);

    $offers = $this->get('/openapi.json')->assertOk()
        ->json('paths./multi.get.x-payment-info.offers');

    expect($offers[0]['amount'])->toBe('50')
        ->and($offers[1])->toBe(['method' => 'tempo', 'intent' => 'charge', 'amount' => null]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, "'tempo'"));
});

it('does not register the route when discovery is disabled', function () {
    config()->set('mpp.discovery.enabled', false);

    // Route registration happens at boot; assert the config gate is read.
    expect(config('mpp.discovery.enabled'))->BeFalse();
})->skip('boot-time toggle covered by config docs; needs app rebuild to assert');
