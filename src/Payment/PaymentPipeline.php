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
 * A route can declare its payment two ways — middleware arguments, or a
 * #[RequiresPayment] attribute enforced automatically — and each has its own
 * middleware. Only where the requirement comes FROM differs; everything after
 * that is identical for both, and lives here exactly once:
 *
 *   resolve the spec  ->  price it  ->  run its preconditions  ->  gate
 *                                          |            |
 *                                     reject with    serve free
 *                                     a Response     (no charge)
 *
 * Keeping this in one place is not a tidiness preference. When it was spread
 * across the middleware, the attribute path silently skipped preconditions,
 * because it reaches the gate without passing through the `mpp` middleware at
 * all. Any future request-scoped concern that must run exactly once per guarded
 * request belongs in run(), where neither entry point can miss it.
 *
 * The gate is left to do only what its own docblock describes: decide how an
 * already-priced, already-vetted request pays. It never sees a free spec.
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
     * Guard a route from its `mpp` middleware arguments. With no arguments the
     * route's #[RequiresPayment] attribute supplies the terms instead, and its
     * absence is a misconfiguration: `mpp` was asked for and nothing declared.
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
     * Guard a route from an attribute the caller has already read off it. Takes
     * the attribute rather than the route because reading it costs reflection on
     * every request, and the automatic enforcer has to read it anyway to decide
     * whether the route is guarded at all.
     */
    public function fromAttribute(Request $request, Closure $next, RequiresPayment $attribute): Response
    {
        return $this->run($request, $next, $this->specs->fromAttribute($attribute, $request));
    }

    private function run(Request $request, Closure $next, PaymentSpec $spec): Response
    {
        // Price first, so the preconditions (and everything downstream) see the
        // amount this request will actually be charged rather than the route's
        // static one. The resolved price only becomes binding once minted into a
        // signed challenge — settlement always verifies against that challenge.
        $spec = $this->pricing->apply($request, $spec);

        // Someone has to have named a price by now — the route, a global default,
        // or a resolver. Asserted before the preconditions run, so a check is
        // always handed a real amount and never has to consider a null one.
        if (! $spec->free && ! $spec->isPriced()) {
            throw new UnpriceableRequestException($this->noPriceMessage($request, $spec));
        }

        if ($response = $this->preconditions->run($request, $spec)) {
            return $response;
        }

        // A resolver may waive the charge outright for this request. No
        // challenge, no session, no receipt — just the resource.
        if ($spec->free) {
            return $next($request);
        }

        return $this->gate->process($request, $next, $spec);
    }

    /**
     * Two different mistakes land here, so the message names which one it is.
     *
     * With no resolvers, the route simply never stated a price. With resolvers,
     * they all declined — which usually means one of them meant to waive the
     * charge and returned null for it. Null is "no opinion"; waiving is
     * `['free' => true]`, and the distinction matters most on exactly this kind
     * of route, where nothing else supplies a price to fall back on.
     */
    private function noPriceMessage(Request $request, PaymentSpec $spec): string
    {
        $route = trim($request->method().' '.($request->route()?->uri() ?? $request->path()));

        // Read from the price resolver rather than rebuilding the list, so the
        // message can only ever name the resolvers that actually ran.
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
