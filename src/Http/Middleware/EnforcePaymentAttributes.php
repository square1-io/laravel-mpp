<?php

namespace Square1\Mpp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Square1\Mpp\Payment\AttributeResolver;
use Square1\Mpp\Payment\PaymentPipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the #[RequiresPayment] attribute automatically.
 *
 * The package registers this middleware on the configured route groups when
 * `mpp.attributes.enabled` is true. A site owner then annotates a controller
 * action, and wires no middleware for each route.
 *
 * The middleware runs after routing, because it inspects the matched route.
 * Route caching therefore works. It passes through a route with no attribute,
 * and a route that already carries the `mpp` middleware, so that the package
 * does not charge twice.
 *
 * This class decides only whether the route is guarded. It passes an annotated
 * route to the same PaymentPipeline that the `mpp` middleware uses. The pricing,
 * the preconditions and the settlement therefore behave in the same way, however
 * a site owner declared the route.
 */
class EnforcePaymentAttributes
{
    public function __construct(private readonly PaymentPipeline $payments) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        // Read the attribute once. To resolve it costs reflection on each
        // request, and the pipeline needs the same instance that this check
        // used.
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
