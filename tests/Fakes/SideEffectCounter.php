<?php

namespace Square1\Mpp\Tests\Fakes;

/**
 * A process-wide counter a test route bumps on each execution, so a test can
 * prove the protected action runs exactly once — settlement runs it, an
 * idempotent replay must NOT run it again.
 */
class SideEffectCounter
{
    public static int $hits = 0;

    public static function reset(): void
    {
        self::$hits = 0;
    }
}
