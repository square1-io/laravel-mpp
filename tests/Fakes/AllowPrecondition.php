<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentSpec;
use Symfony\Component\HttpFoundation\Response;

/**
 * A precondition that always passes (returns null), recording how often it ran.
 */
class AllowPrecondition
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function check(Request $request, PaymentSpec $spec): ?Response
    {
        self::$calls++;

        return null;
    }
}
