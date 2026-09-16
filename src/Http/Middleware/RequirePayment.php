<?php

namespace Square1\Mpp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentPipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a route behind an MPP payment. The package aliases it as `mpp`.
 *
 *   ->middleware('mpp:0.50,USD')                               // once-off
 *   ->middleware('mpp:5.00,USD,grants=10,scope=report.basic')  // metered bundle
 *   ->middleware('mpp:report.basic')                           // price_book key
 *   ->middleware('mpp:5.00,USD,pricing=tiered')                // price per request
 *   ->middleware('mpp')   + #[RequiresPayment(...)] on the action
 *
 * The PaymentPipeline owns the meaning of the arguments, and every step after
 * that. The automatic attribute enforcer shares the pipeline, so neither route
 * style can behave differently from the other.
 */
class RequirePayment
{
    public function __construct(private readonly PaymentPipeline $payments) {}

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        return $this->payments->fromArgs($request, $next, $args);
    }
}
