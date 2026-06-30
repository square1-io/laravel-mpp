<?php

namespace Square1\Mpp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\AttributeResolver;
use Square1\Mpp\Payment\PaymentGate;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Payment\SpecResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a route behind an MPP payment. Aliased as `mpp`.
 *
 *   ->middleware('mpp:0.50,USD')                               // once-off
 *   ->middleware('mpp:5.00,USD,grants=10,scope=report.basic')  // metered bundle
 *   ->middleware('mpp:report.basic')                           // price_book key
 *   ->middleware('mpp')   + #[RequiresPayment(...)] on the action
 */
class RequirePayment
{
    public function __construct(
        private readonly PaymentGate $gate,
        private readonly SpecResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $spec = $args !== []
            ? $this->resolver->fromMiddlewareArgs($args, $request)
            : $this->specFromAttribute($request);

        if ($response = $this->checkPreconditions($request, $spec)) {
            return $response;
        }

        return $this->gate->process($request, $next, $spec);
    }

    /**
     * Run the route's preconditions before any challenge is minted or payment
     * settled, so a request that can never be fulfilled (a missing resource, a
     * blocked user) is rejected without charging. Global checks run first, then
     * the route's own, in declared order and de-duplicated. The first check that
     * returns a Response short-circuits; the rest do not run.
     *
     * Each name resolves from `mpp.preconditions.checks` to a [Class::class,
     * 'method'] pair (container-resolved, config:cache-safe) and is called with
     * (Request, PaymentSpec), returning a Response to reject or null to proceed.
     * An unknown name fails closed, so a typo can never silently skip a check.
     */
    private function checkPreconditions(Request $request, PaymentSpec $spec): ?Response
    {
        $checks = (array) config('mpp.preconditions.checks', []);

        $names = array_values(array_unique(array_merge(
            (array) config('mpp.preconditions.global', []),
            $spec->preconditions,
        )));

        foreach ($names as $name) {
            $check = $checks[$name] ?? null;

            if (! is_array($check) || count($check) !== 2 || ! is_string($check[0])) {
                throw new InvalidConfigurationException(
                    "Unknown precondition '{$name}'. Define it under mpp.preconditions.checks "
                    ."as a [Class::class, 'method'] pair."
                );
            }

            [$class, $method] = $check;

            $response = app($class)->{$method}($request, $spec);

            if ($response instanceof Response) {
                return $response;
            }
        }

        return null;
    }

    private function specFromAttribute(Request $request): PaymentSpec
    {
        $attribute = AttributeResolver::forRoute($request->route());

        if ($attribute === null) {
            throw new InvalidConfigurationException(
                'The mpp middleware was used without arguments and the action has no #[RequiresPayment] attribute.'
            );
        }

        return $this->resolver->fromAttribute($attribute, $request);
    }
}
