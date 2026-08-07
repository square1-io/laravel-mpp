<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Http\Request;
use Square1\Mpp\Payment\PaymentSpec;

/**
 * A second, independent price resolver, so composition and ordering (global then
 * route-specific) can be asserted with two distinct call records.
 */
class RegionPricing
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
