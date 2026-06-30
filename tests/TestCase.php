<?php

namespace Square1\Mpp\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Square1\Mpp\Http\Middleware\EnforcePaymentAttributes;
use Square1\Mpp\MppServiceProvider;
use Square1\Mpp\Tests\Fakes\FakeTempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeVerifier;
use Square1\Mpp\Tests\Fakes\PaidController;

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
        // Settle deterministically without touching Stripe.
        $app['config']->set('mpp.methods.stripe.verifier', FakeVerifier::class);

        // A second rail for multi-accept / gate-routing tests. `accept` stays
        // null so single-method routes keep offering only stripe (back-compat),
        // even though tempo is configured.
        //
        // The `token`/`recipient`/`chain_id`/`decimals` make a tempo-PRIMARY route
        // mint a real mppx-dialect challenge; the FakeTempoVerifier still backs the
        // NATIVE multi-rail path (where tempo is a non-primary accept entry).
        $app['config']->set('mpp.methods.tempo', [
            'verifier' => FakeTempoVerifier::class,
            'network_id' => 'tempo-testnet',
            'payment_method_types' => ['stablecoin'],
            'token' => '0x20c0000000000000000000000000000000000000',
            'recipient' => '0x0dcd39a3f85aa288c1b2825bc41eb7e9bb2abf70',
            'chain_id' => 42431,
            'decimals' => 6,
            'realm' => 'localhost',
            'confirmations' => 1,
            'poll_attempts' => 1,
            'poll_delay_ms' => 0,
        ]);
    }

    protected function defineRoutes($router): void
    {
        // Middleware-argument routes.
        Route::get('/clip', fn () => response('CLIP', 200))->middleware('mpp:0.50,USD');
        Route::get('/report', fn () => response()->json(['report' => 'ok']))
            ->middleware('mpp:5.00,USD,grants=10,scope=report.basic');

        // Multi-rail route: offers stripe + tempo via the middleware `methods=` arg.
        Route::get('/multi', fn () => response('MULTI', 200))
            ->middleware('mpp:0.50,USD,methods=stripe|tempo,scope=multi.clip');

        // Tempo-PRIMARY route: speaks the mppx wire dialect end to end. 0.01 USD
        // maps to 10000 pathUSD minor units at 6 decimals.
        Route::get('/tempo', fn () => response('TEMPO', 200))
            ->middleware('mpp:0.01,USD,method=tempo,scope=tempo.clip');

        // Attribute via explicit `mpp` middleware (no args).
        Route::get('/attr/explicit', [PaidController::class, 'clip'])->middleware('mpp');

        // Attribute auto-enforced by the EnforcePaymentAttributes middleware on a group.
        Route::middleware(EnforcePaymentAttributes::class)->group(function () {
            Route::get('/attr/auto', [PaidController::class, 'clip']);
            Route::get('/attr/report', [PaidController::class, 'report']);
            Route::get('/attr/plain', fn () => response('FREE', 200)); // no attribute -> passes through
        });
    }
}
