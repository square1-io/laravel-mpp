<?php

namespace Square1\Mpp\Tests\Fakes;

/**
 * A data object inside another data object.
 */
final class ClipSource
{
    public function __construct(
        public readonly string $id,
        public readonly int $width,
    ) {}
}
