<?php

use Illuminate\Support\Facades\Route;

// The conformance-oracle endpoint (see CLAUDE.md): one route, both rails.
// MPP_ACCEPT=stripe|tempo (testbench.yaml) makes the 402 carry one Payment
// challenge per rail; the client picks via Accept-Payment. 0.50 USD is
// "50" cent minor units on the stripe challenge and "500000" pathUSD
// minor units (6 decimals) on the tempo one.
Route::get('/paid', fn () => response()->json([
    'data' => 'the paid resource',
    'paidAt' => now()->toIso8601String(),
]))->middleware('mpp:0.50,USD,scope=paid');
