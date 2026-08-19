<?php

namespace Square1\Mpp\Tests\Fakes;

use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Settlement\Verifier;

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

        // Mirror the real Stripe rail's payload requirement so routing tests
        // exercise genuine behaviour: no SPT, no settlement.
        if ($credential->spt() === null) {
            return SettlementResult::failure('No SPT presented.');
        }

        if (! self::$succeed) {
            return SettlementResult::failure('Fake verifier declined.');
        }

        return SettlementResult::settled(
            settlementRef: 'pi_fake_'.self::$calls,
            amountMinor: (int) $challenge->amount(),
            currency: preg_match('/^[a-z]{3}$/i', $challenge->currency()) ? $challenge->currency() : null,
        );
    }
}
