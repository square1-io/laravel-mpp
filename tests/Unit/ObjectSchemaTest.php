<?php

use Square1\Mpp\Discovery\ObjectSchema;
use Square1\Mpp\Tests\Fakes\ClipResult;
use Square1\Mpp\Tests\Fakes\CycleNode;
use Square1\Mpp\Tests\Fakes\ImportedList;
use Square1\Mpp\Tests\Fakes\SideEffectCounter;

it('stops at a class that holds itself', function () {
    // Without the guard, the reader would descend for as long as the type
    // allows, which is for ever.
    expect(ObjectSchema::fromClass(CycleNode::class))->toBe([
        'type' => 'object',
        'properties' => ['id' => ['type' => 'string']],
        'required' => ['id'],
    ]);
});

it('returns null for a class whose properties state nothing', function () {
    // A static property belongs to the class and not to an instance, so it
    // describes no part of a response body.
    expect(ObjectSchema::fromClass(SideEffectCounter::class))->toBeNull();
});

it('marks a promoted property with a default as optional', function () {
    // A promoted property holds its default on the constructor parameter, and
    // not on the property itself. hasDefaultValue() alone therefore reports
    // that `cached` and `tags` are required, which they are not.
    $schema = ObjectSchema::fromClass(ClipResult::class);

    expect($schema['required'])->toBe(['url', 'format', 'source', 'session'])
        ->and($schema['properties'])->toHaveKey('cached');
});

it('resolves a class that the docblock names through an import', function () {
    // The docblock writes `Stamp`, which the file imports as an alias of
    // DateTimeImmutable. The reader applies the same rules as PHP.
    expect(ObjectSchema::fromClass(ImportedList::class))->toBe([
        'type' => 'object',
        'properties' => [
            'stamps' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
    ]);
});
