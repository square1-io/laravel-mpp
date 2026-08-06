<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentSpec;
use Symfony\Component\HttpFoundation\Response;

/**
 * A precondition that always passes (returns null), recording how often it ran
 * and the price it was handed — so tests can assert a check sees the price the
 * request will actually be charged, not the route's static one.
 */
class AllowPrecondition
{
    public static int $calls = 0;

    /** @var list<string> */
    public static array $sawAmounts = [];

    public static function reset(): void
    {
        self::$calls = 0;
        self::$sawAmounts = [];
    }

    public function check(Request $request, PaymentSpec $spec): ?Response
    {
        self::$calls++;
        self::$sawAmounts[] = $spec->amount;

        return null;
    }
}
