<?php

namespace Square1\Mpp\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Square1\Mpp\Http\Middleware\EnforcePaymentAttributes;
use Square1\Mpp\MppServiceProvider;
use Square1\Mpp\Tests\Fakes\AllowPrecondition;
use Square1\Mpp\Tests\Fakes\DenyPrecondition;
use Square1\Mpp\Tests\Fakes\FakeTempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeVerifier;
use Square1\Mpp\Tests\Fakes\PaidController;
use Square1\Mpp\Tests\Fakes\RegionPricing;
use Square1\Mpp\Tests\Fakes\SideEffectCounter;
use Square1\Mpp\Tests\Fakes\TieredPricing;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [MppServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mpp.secret', 'test-challenge-secret-do-not-use-in-production');
        $app['config']->set('cache.default', 'array');
        // The test HTTP kernel serves over http; allow it (production requires TLS).
        $app['config']->set('mpp.allow_insecure', true);
        // Settle deterministically without touching Stripe. network_id and
        // payment_method_types are set so a stripe challenge is spec-conformant
        // (the Stripe method requires both in methodDetails).
        $app['config']->set('mpp.methods.stripe.verifier', FakeVerifier::class);
        $app['config']->set('mpp.methods.stripe.network_id', 'profile_test');
        $app['config']->set('mpp.methods.stripe.payment_method_types', ['card']);

        // A second rail for multi-accept / gate-routing tests. `accept` stays
        // null so single-method routes keep offering only stripe (back-compat),
        // even though tempo is configured.
        //
        // The `token`/`recipient`/`chain_id`/`decimals` let a tempo route mint a
        // real challenge; the FakeTempoVerifier stands in for settlement.
        $app['config']->set('mpp.methods.tempo', [
            'verifier' => FakeTempoVerifier::class,
            'token' => '0x20C000000000000000000000b9537d11c60E8b50',
            'recipient' => '0x0dcd39a3f85aa288c1b2825bc41eb7e9bb2abf70',
            'chain_id' => 4217,
            'decimals' => 6,
            'confirmations' => 1,
            'poll_attempts' => 1,
            'poll_delay_ms' => 0,
        ]);

        // Named precondition checks for the precondition tests. `global` stays
        // empty by default; tests set it per-case.
        $app['config']->set('mpp.preconditions.checks', [
            'allow' => [AllowPrecondition::class, 'check'],
            'deny' => [DenyPrecondition::class, 'check'],
        ]);

        // Named price resolvers for the dynamic-pricing tests. Both decline by
        // default (their static $overrides is null), so a route carrying them
        // keeps its static price until a test says otherwise. `global` stays
        // empty by default.
        $app['config']->set('mpp.pricing.resolvers', [
            'tiered' => [TieredPricing::class, 'price'],
            'region' => [RegionPricing::class, 'price'],
        ]);

        // A price_book entry carrying its own resolver list.
        $app['config']->set('mpp.price_book', [
            'report.dynamic' => ['amount' => '5.00', 'currency' => 'USD', 'pricing' => ['tiered']],
        ]);
    }

    protected function defineRoutes($router): void
    {
        // Middleware-argument routes.
        Route::get('/clip', fn () => response('CLIP', 200))->middleware('mpp:0.50,USD');
        Route::get('/report', fn () => response()->json(['report' => 'ok']))
            ->middleware('mpp:5.00,USD,grants=10,scope=report.basic');

        // A route whose action has a side effect (bumps a counter), so a test can
        // prove settlement runs the action once and a replay does not re-run it.
        Route::get('/sideeffect', function () {
            SideEffectCounter::$hits++;

            return response()->json(['hits' => SideEffectCounter::$hits]);
        })->middleware('mpp:0.50,USD,scope=sideeffect');

        // Two routes deliberately sharing ONE scope at different prices, to prove
        // a challenge is bound to its route identity, not just its scope.
        Route::get('/shared/cheap', fn () => response('CHEAP', 200))
            ->middleware('mpp:0.50,USD,scope=shared');
        Route::get('/shared/pricey', fn () => response('PRICEY', 200))
            ->middleware('mpp:5.00,USD,scope=shared');

        // A body-carrying route, to prove the RFC 9530 request-body digest binding.
        Route::post('/body', fn () => response('BODY', 200))
            ->middleware('mpp:0.50,USD,scope=body');

        // A PARAMETERIZED route (one pattern, many concrete targets) and a
        // query-driven route, to prove challenges bind the concrete request
        // target, not just the route pattern. Both share one scope so only the
        // target distinguishes them.
        Route::get('/item/{tier}', fn (string $tier) => response('ITEM '.$tier, 200))
            ->middleware('mpp:0.50,USD,scope=item');
        Route::get('/q', fn () => response('Q', 200))
            ->middleware('mpp:0.50,USD,scope=q');

        // A route that sets extra response headers, to prove the replay snapshot
        // restores all of them (not just Content-Type).
        Route::get('/hdr', fn () => response('HDR', 200)
            ->header('X-Custom', 'xyz')
            ->header('Location', '/elsewhere'))
            ->middleware('mpp:0.50,USD,scope=hdr');

        // A streamed response, whose body is never buffered: the gate must not
        // snapshot it, so a lost-response retry fails safe to a fresh challenge.
        Route::get('/stream', fn () => new StreamedResponse(function () {
            echo 'STREAMED';
        }, 200))->middleware('mpp:0.50,USD,scope=stream');

        // Multi-rail route: offers stripe + tempo via the middleware `methods=` arg.
        Route::get('/multi', fn () => response('MULTI', 200))
            ->middleware('mpp:0.50,USD,methods=stripe|tempo,scope=multi.clip');

        // Tempo-PRIMARY route: speaks the mppx wire dialect end to end. 0.01 USD
        // maps to 10000 pathUSD minor units at 6 decimals.
        Route::get('/tempo', fn () => response('TEMPO', 200))
            ->middleware('mpp:0.01,USD,method=tempo,scope=tempo.clip');

        // Precondition routes (checks registered in defineEnvironment).
        Route::get('/precond/open', fn () => response('OPEN', 200))
            ->middleware('mpp:0.50,USD,scope=precond.open');
        Route::get('/precond/allow', fn () => response('OK', 200))
            ->middleware('mpp:0.50,USD,scope=precond.allow,preconditions=allow');
        Route::get('/precond/deny', fn () => response('OK', 200))
            ->middleware('mpp:0.50,USD,scope=precond.deny,preconditions=deny');
        Route::get('/precond/unknown', fn () => response('OK', 200))
            ->middleware('mpp:0.50,USD,scope=precond.unknown,preconditions=ghost');

        // Dynamic pricing routes. `/price/tiered` carries a static $5 fallback the
        // resolver may override; `/price/open` carries none, so `global` applies.
        Route::get('/price/tiered', fn () => response('TIERED', 200))
            ->middleware('mpp:5.00,USD,scope=price.tiered,pricing=tiered');
        Route::get('/price/open', fn () => response('OPEN', 200))
            ->middleware('mpp:5.00,USD,scope=price.open');
        Route::get('/price/both', fn () => response('BOTH', 200))
            ->middleware('mpp:5.00,USD,scope=price.both,pricing=tiered|region');
        Route::get('/price/metered', fn () => response()->json(['report' => 'ok']))
            ->middleware('mpp:5.00,USD,grants=10,scope=price.metered,pricing=tiered');
        Route::get('/price/tempo', fn () => response('TEMPO', 200))
            ->middleware('mpp:0.01,USD,method=tempo,scope=price.tempo,pricing=tiered');
        Route::get('/price/unknown', fn () => response('OK', 200))
            ->middleware('mpp:5.00,USD,scope=price.unknown,pricing=ghost');
        Route::get('/price/precond', fn () => response('OK', 200))
            ->middleware('mpp:5.00,USD,scope=price.precond,pricing=tiered,preconditions=allow');
        Route::get('/price/book', fn () => response('BOOK', 200))
            ->middleware('mpp:report.dynamic');

        // Attribute via explicit `mpp` middleware (no args).
        Route::get('/attr/explicit', [PaidController::class, 'clip'])->middleware('mpp');

        // `mpp` with neither arguments nor an attribute to read: a misconfiguration.
        Route::get('/attr/missing', fn () => response('OK', 200))->middleware('mpp');

        // Resolver-owned pricing: the route states no amount at all and leaves it
        // to `tiered`. Unpriced until a resolver says otherwise.
        Route::get('/price/resolver-owned', fn () => response('OWNED', 200))
            ->middleware('mpp:scope=price.owned,pricing=tiered');

        // No amount and no resolver either: nothing can ever price this.
        Route::get('/price/nothing', fn () => response('NOTHING', 200))
            ->middleware('mpp:scope=price.nothing');

        // Attribute auto-enforced by the EnforcePaymentAttributes middleware on a group.
        Route::middleware(EnforcePaymentAttributes::class)->group(function () {
            Route::get('/attr/auto', [PaidController::class, 'clip']);
            Route::get('/attr/report', [PaidController::class, 'report']);
            Route::get('/attr/plain', fn () => response('FREE', 200)); // no attribute -> passes through
            // Auto-enforced routes must honour the attribute's pricing and
            // preconditions too — they never pass through the `mpp` middleware.
            Route::get('/attr/tiered', [PaidController::class, 'tiered']);
            Route::get('/attr/guarded', [PaidController::class, 'guarded']);
        });
    }
}
