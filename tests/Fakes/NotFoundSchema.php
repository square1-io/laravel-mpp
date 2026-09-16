<?php

namespace Square1\Mpp\Tests\Fakes;

use Square1\Mpp\Discovery\ProvidesSchema;

class NotFoundSchema implements ProvidesSchema
{
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['error' => ['type' => 'string']],
        ];
    }
}
