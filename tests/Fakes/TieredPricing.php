<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentSpec;

/**
 * A price resolver whose answer the test dictates. `$overrides` is returned as-is
 * (null = decline and keep the route's static price); `$using` takes precedence
 * when set, for cases that depend on the request or the incoming spec.
 *
 * `$sawAmounts` records the amount on the spec as each call received it, so tests
 * can assert that resolvers compose in order rather than each seeing the original.
 *
 * Kept deliberately identical to RegionPricing: the tests need two separately
 * named resolvers to assert ordering, not two different recording behaviours.
 */
class TieredPricing
{
    /** @var array<string, mixed>|null */
    public static ?array $overrides = null;

    /** @var (callable(Request, PaymentSpec): (array<string, mixed>|null))|null */
    public static $using = null;

    public static int $calls = 0;

    /** @var list<string> */
    public static array $sawAmounts = [];

    public static function reset(): void
    {
        self::$overrides = null;
        self::$using = null;
        self::$calls = 0;
        self::$sawAmounts = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function price(Request $request, PaymentSpec $spec): ?array
    {
        self::$calls++;
        self::$sawAmounts[] = $spec->amount;

        if (self::$using !== null) {
            return (self::$using)($request, $spec);
        }

        return self::$overrides;
    }
}
