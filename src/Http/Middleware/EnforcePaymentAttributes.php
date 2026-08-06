<?php

namespace Square1\Mpp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Square1\Mpp\Payment\AttributeResolver;
use Square1\Mpp\Payment\PaymentPipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-enforces the #[RequiresPayment] attribute. Registered on the configured
 * route groups when `mpp.attributes.enabled` is true, so annotating a controller
 * action is all that's needed — no per-route middleware wiring.
 *
 * Runs after routing (it inspects the matched route), so route caching is fine.
 * Passes through unattributed routes, and routes already carrying the `mpp`
 * middleware (to avoid charging twice).
 *
 * Deciding whether the route is guarded is all this does. An attributed route is
 * handed to the same PaymentPipeline the `mpp` middleware uses, so pricing,
 * preconditions and settlement behave identically however a route was declared.
 */
class EnforcePaymentAttributes
{
    public function __construct(private readonly PaymentPipeline $payments) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        // Read once: resolving the attribute costs reflection per request, and
        // the pipeline needs the same instance this check was made on.
        $attribute = AttributeResolver::forRoute($route);

        if ($attribute === null || $this->alreadyGuarded($route)) {
            return $next($request);
        }

        return $this->payments->fromAttribute($request, $next, $attribute);
    }

    private function alreadyGuarded(?Route $route): bool
    {
        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && (str_starts_with($middleware, 'mpp') || $middleware === RequirePayment::class)) {
                return true;
            }
        }

        return false;
    }
}
