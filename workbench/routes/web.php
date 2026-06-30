<?php

use Illuminate\Support\Facades\Route;

// A route gated by the tempo (mppx-dialect) rail for the live e2e test. 0.01 USD
// maps to 10000 pathUSD minor units at 6 decimals.
Route::get('/paid', fn () => response()->json([
    'data' => 'the paid resource',
    'paidAt' => now()->toIso8601String(),
]))->middleware('mpp:0.01,USD,method=tempo,scope=paid');
