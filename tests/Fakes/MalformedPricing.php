<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentSpec;

/**
 * A resolver that returns something other than an array or null. Deliberately
 * untyped — a userland resolver need not declare a return type, so the package
 * has to catch the shape itself rather than rely on a TypeError.
 */
class MalformedPricing
{
    public function price(Request $request, PaymentSpec $spec)
    {
        return '2.00';
    }
}
