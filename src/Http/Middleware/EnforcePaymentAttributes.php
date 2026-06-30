<?php

namespace Square1\Mpp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Square1\Mpp\Payment\AttributeResolver;
use Square1\Mpp\Payment\PaymentGate;
use Square1\Mpp\Payment\SpecResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-enforces the #[RequiresPayment] attribute. Registered on the configured
 * route groups when `mpp.attributes.enabled` is true, so annotating a controller
 * action is all that's needed — no per-route middleware wiring.
 *
 * Runs after routing (it inspects the matched route), so route caching is fine.
 * Passes through unattributed routes, and routes already carrying the `mpp`
 * middleware (to avoid charging twice).
 */
class EnforcePaymentAttributes
{
    public function __construct(
        private readonly PaymentGate $gate,
        private readonly SpecResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $attribute = AttributeResolver::forRoute($route);

        if ($attribute === null || $this->alreadyGuarded($route)) {
            return $next($request);
        }

        return $this->gate->process($request, $next, $this->resolver->fromAttribute($attribute, $request));
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
