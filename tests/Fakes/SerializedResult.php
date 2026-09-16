<?php

namespace Square1\Mpp\Tests\Fakes;

use JsonSerializable;

/**
 * A class that renames its own keys on the way out. Its properties therefore
 * do not state what it serializes to, and the package refuses to guess.
 */
final class SerializedResult implements JsonSerializable
{
    public function __construct(public readonly string $clipUrl) {}

    public function jsonSerialize(): array
    {
        return ['clip_url' => $this->clipUrl];
    }
}
