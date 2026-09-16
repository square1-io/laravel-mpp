<?php

namespace Square1\Mpp\Tests\Fakes;

/**
 * A data object that holds another of its own kind.
 */
final class CycleNode
{
    public function __construct(
        public readonly string $id,
        public readonly ?CycleNode $child = null,
    ) {}
}
