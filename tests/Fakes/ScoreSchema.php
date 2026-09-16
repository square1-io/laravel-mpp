<?php

namespace Square1\Mpp\Tests\Fakes;

use Square1\Mpp\Discovery\ProvidesSchema;

/**
 * A response shape that a class states, rather than an inline array.
 */
class ScoreSchema implements ProvidesSchema
{
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['score'],
            'properties' => ['score' => ['type' => 'integer']],
        ];
    }
}
