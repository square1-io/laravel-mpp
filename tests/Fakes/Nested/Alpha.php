<?php

namespace Square1\Mpp\Tests\Fakes\Nested;

/**
 * A data object in a second namespace, for the grouped-import tests.
 */
final class Alpha
{
    public function __construct(public readonly string $alpha) {}
}
