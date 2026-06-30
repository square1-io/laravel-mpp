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

        return $this->gate->process($request, $next, $spec);
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
