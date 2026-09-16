<?php

namespace Square1\Mpp\Payment;

use Closure;
use Illuminate\Http\Request;
use Square1\Mpp\Attributes\RequiresPayment;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Exceptions\UnpriceableRequestException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one path from a guarded route to the payment gate.
 *
 * A route can declare its payment in two ways: through middleware arguments,
 * or through a #[RequiresPayment] attribute that the package enforces
 * automatically. Each way has its own middleware. Only the source of the
 * requirement differs. Every step after that is the same for both, and this
 * class holds those steps once:
 *
 *   resolve the spec  ->  price it  ->  run its preconditions  ->  gate
 *                                          |            |
 *                                     reject with    serve free
 *                                     a Response     (no charge)
 *
 * These steps are in one place for a reason. When the middleware held them,
 * the attribute path skipped the preconditions, because it reaches the gate
 * without the `mpp` middleware. Any future concern that must run exactly once
 * per guarded request belongs in run(), where neither entry point can omit it.
 *
 * The gate then does only what its own docblock describes. It decides how a
 * request that the package has priced and checked pays. It never receives a
 * free spec.
 */
class PaymentPipeline
{
    public function __construct(
        private readonly SpecResolver $specs,
        private readonly PriceResolver $pricing,
        private readonly PreconditionRunner $preconditions,
        private readonly PaymentGate $gate,
    ) {}

    /**
     * Guards a route from the arguments of its `mpp` middleware.
     *
     * With no arguments, the #[RequiresPayment] attribute of the route states
     * the terms. If the attribute is also absent, the route is misconfigured:
     * the route asked for `mpp` and declared nothing.
     *
     * @param  list<string>  $args
     */
    public function fromArgs(Request $request, Closure $next, array $args): Response
    {
        $spec = $args !== []
            ? $this->specs->fromMiddlewareArgs($args, $request)
            : $this->specs->fromAttribute($this->requireAttribute($request), $request);

        return $this->run($request, $next, $spec);
    }

    /**
     * Guards a route from an attribute that the caller has already read.
     *
     * The method takes the attribute and not the route. To read the attribute
     * costs reflection on every request, and the automatic enforcer must read it
     * in any case, to decide whether the route is guarded.
     */
    public function fromAttribute(Request $request, Closure $next, RequiresPayment $attribute): Response
    {
        return $this->run($request, $next, $this->specs->fromAttribute($attribute, $request));
    }

    private function run(Request $request, Closure $next, PaymentSpec $spec): Response
    {
        // Price the request first, so that the preconditions and every later
        // step see the amount that the package will charge, and not the static
        // amount of the route. The resolved price becomes binding only when the
        // package mints it into a signed challenge. Settlement always verifies
        // against that challenge.
        $spec = $this->pricing->apply($request, $spec);

        // Something must have named a price by this point: the route, a global
        // default, or a resolver. The pipeline asserts this before the
        // preconditions run, so every check receives a real amount and never
        // has to handle a null one.
        if (! $spec->free && ! $spec->isPriced()) {
            throw new UnpriceableRequestException($this->noPriceMessage($request, $spec));
        }

        if ($response = $this->preconditions->run($request, $spec)) {
            return $response;
        }

        // A resolver can waive the charge for this request. There is then no
        // challenge, no session and no receipt. The package serves the
        // resource.
        if ($spec->free) {
            return $next($request);
        }

        return $this->gate->process($request, $next, $spec);
    }

    /**
     * Reports one of two different mistakes, and names which one it is.
     *
     * With no resolvers, the route never stated a price. With resolvers, every
     * resolver declined. That usually means that one of them intended to waive
     * the charge and returned null. Null means "no opinion". To waive the charge
     * is `['free' => true]`. The difference matters most on this kind of route,
     * where nothing else supplies a price.
     */
    private function noPriceMessage(Request $request, PaymentSpec $spec): string
    {
        $route = trim($request->method().' '.($request->route()?->uri() ?? $request->path()));

        // Read the names from the price resolver, and do not rebuild the list.
        // The message can then name only the resolvers that ran.
        $ran = $this->pricing->namesFor($spec);

        $cause = $ran === []
            ? 'Give it an amount (e.g. mpp:0.50,USD), set a global default (MPP_DEFAULT_AMOUNT / '
                .'mpp.defaults.amount), reference a price_book key, use a #[RequiresPayment] '
                .'attribute, or attach a price resolver with `pricing=` and have it return one.'
            : 'It states no amount, and the price resolver(s) that ran ('.implode(', ', $ran).') '
                ."all declined by returning null. Return an `['amount' => …]` from one of them, "
                .'give the route a fallback amount, or — if this request should not be charged at '
                ."all — return `['free' => true]` rather than null.";

        return "No price for route [{$route}]. {$cause}";
    }

    private function requireAttribute(Request $request): RequiresPayment
    {
        $attribute = AttributeResolver::forRoute($request->route());

        if ($attribute === null) {
            throw new InvalidConfigurationException(
                'The mpp middleware was used without arguments and the action has no #[RequiresPayment] attribute.'
            );
        }

        return $attribute;
    }
}
