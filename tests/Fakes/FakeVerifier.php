<?php

namespace Square1\Mpp\Tests\Fakes;

use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Settlement\Verifier;
use Square1\Mpp\Support\Money;

/**
 * Deterministic verifier for feature tests — settles without touching Stripe.
 */
class FakeVerifier implements Verifier
{
    public static bool $succeed = true;

    public static int $calls = 0;

    /** @var array<string, mixed> */
    public static array $lastContext = [];

    public static function reset(): void
    {
        self::$succeed = true;
        self::$calls = 0;
        self::$lastContext = [];
    }

    public function verify(Credential $credential, Challenge $challenge, array $context = []): SettlementResult
    {
        self::$calls++;
        self::$lastContext = $context;

        if (! self::$succeed) {
            return SettlementResult::failure('Fake verifier declined.');
        }

        return SettlementResult::settled(
            settlementRef: 'pi_fake_'.self::$calls,
            amountMinor: Money::toMinorUnits($challenge->amount, $challenge->currency),
            currency: $challenge->currency,
        );
    }
}
