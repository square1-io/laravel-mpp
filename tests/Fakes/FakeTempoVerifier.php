<?php

namespace Square1\Mpp\Tests\Fakes;

use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Settlement\Verifier;
use Square1\Mpp\Support\Money;

/**
 * Deterministic Tempo-rail verifier for feature tests — settles a presented
 * proof without touching a chain.
 */
class FakeTempoVerifier implements Verifier
{
    public static bool $succeed = true;

    public static int $calls = 0;

    public static function reset(): void
    {
        self::$succeed = true;
        self::$calls = 0;
    }

    public function verify(Credential $credential, Challenge $challenge, array $context = []): SettlementResult
    {
        self::$calls++;

        if (! $credential->isSettlementProof()) {
            return SettlementResult::failure('No settlement proof presented.');
        }

        if (! self::$succeed) {
            return SettlementResult::failure('Fake tempo verifier declined.');
        }

        return SettlementResult::settled(
            settlementRef: '0xtempo_'.self::$calls,
            amountMinor: Money::toMinorUnits($challenge->amount, $challenge->currency),
            currency: $challenge->currency,
        );
    }
}
