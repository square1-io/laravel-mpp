<?php

namespace Square1\Mpp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentPipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a route behind an MPP payment. Aliased as `mpp`.
 *
 *   ->middleware('mpp:0.50,USD')                               // once-off
 *   ->middleware('mpp:5.00,USD,grants=10,scope=report.basic')  // metered bundle
 *   ->middleware('mpp:report.basic')                           // price_book key
 *   ->middleware('mpp:5.00,USD,pricing=tiered')                // price per request
 *   ->middleware('mpp')   + #[RequiresPayment(...)] on the action
 *
 * Everything the arguments mean, and everything that happens once they are
 * understood, belongs to the PaymentPipeline — shared with the automatic
 * attribute enforcer so neither route style can drift from the other.
 */
class RequirePayment
{
    public function __construct(private readonly PaymentPipeline $payments) {}

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        return $this->payments->fromArgs($request, $next, $args);
    }
}
