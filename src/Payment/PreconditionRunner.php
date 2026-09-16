<?php

namespace Square1\Mpp\Payment;

use Illuminate\Http\Request;
use Square1\Mpp\Payment\Concerns\ResolvesNamedCallables;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the preconditions of a route before the gate mints a challenge or settles
 * a payment.
 *
 * The package therefore rejects a request that it can never fulfil, such as a
 * request for a missing resource or a request from a blocked user, without a
 * charge.
 *
 * The global checks run first, and then the checks of the route. Both run in the
 * declared order, and the runner removes duplicates. The first check that
 * returns a Response ends the run, and the rest do not run.
 *
 * Each name resolves from `mpp.preconditions.checks` to a [Class::class,
 * 'method'] pair. The container resolves the pair, so the list survives
 * `config:cache`. The runner calls the pair with a Request and a PaymentSpec.
 * The pair returns a Response to reject the request, or null to continue. An
 * unknown name fails closed, so a typo cannot skip a check without a message.
 *
 * The PaymentPipeline drives this class, and no middleware does. A route
 * therefore gets its checks however a site owner declared it. When this logic
 * lived in the `mpp` middleware, a route that the package enforced from its
 * #[RequiresPayment] attribute skipped the checks without a message, because
 * such a route never passes through that middleware.
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
