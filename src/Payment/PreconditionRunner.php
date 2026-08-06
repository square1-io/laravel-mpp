<?php

namespace Square1\Mpp\Payment;

use Illuminate\Http\Request;
use Square1\Mpp\Payment\Concerns\ResolvesNamedCallables;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs a route's preconditions before any challenge is minted or payment
 * settled, so a request that can never be fulfilled (a missing resource, a
 * blocked user) is rejected without charging. Global checks run first, then the
 * route's own, in declared order and de-duplicated. The first check that returns
 * a Response short-circuits; the rest do not run.
 *
 * Each name resolves from `mpp.preconditions.checks` to a [Class::class,
 * 'method'] pair (container-resolved, config:cache-safe) and is called with
 * (Request, PaymentSpec), returning a Response to reject or null to proceed.
 * An unknown name fails closed, so a typo can never silently skip a check.
 *
 * Driven by the PaymentPipeline rather than by a middleware, so a route gets its
 * checks however it was declared. When this lived in the `mpp` middleware, a
 * route auto-enforced from its #[RequiresPayment] attribute skipped them
 * silently, never passing through that middleware at all.
 */
class PreconditionRunner
{
    use ResolvesNamedCallables;

    public function run(Request $request, PaymentSpec $spec): ?Response
    {
        $names = $this->namedList('mpp.preconditions.global', $spec->preconditions);

        if ($names === []) {
            return null;
        }

        $checks = (array) config('mpp.preconditions.checks', []);

        foreach ($names as $name) {
            [$class, $method] = $this->namedCallable($checks, $name, 'precondition', 'mpp.preconditions.checks');

            $response = app($class)->{$method}($request, $spec);

            if ($response instanceof Response) {
                return $response;
            }
        }

        return null;
    }
}
