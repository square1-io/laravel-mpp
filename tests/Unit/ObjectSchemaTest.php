<?php

use Square1\Mpp\Discovery\ObjectSchema;
use Square1\Mpp\Tests\Fakes\ClipResult;
use Square1\Mpp\Tests\Fakes\CollectionShapes;
use Square1\Mpp\Tests\Fakes\CycleNode;
use Square1\Mpp\Tests\Fakes\GroupedImports;
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

// ── Collection types read from a docblock ───────────────────────────────────
// One case for each form that the README states. The fixture carries one
// property per form, so the documented matrix and the tests stay the same
// size.

/**
 * @return array<string, mixed>
 */
function clipSourceSchema(): array
{
    return [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'string'], 'width' => ['type' => 'integer']],
        'required' => ['id', 'width'],
    ];
}

it('reads each documented collection form', function (string $property, array $expected) {
    $properties = ObjectSchema::fromClass(CollectionShapes::class)['properties'];

    expect($properties[$property])->toBe($expected);
})->with([
    // The tag of a property, for a property that the constructor does not
    // promote.
    '@var list<string>' => ['fromVarTag', ['type' => 'array', 'items' => ['type' => 'string']]],

    'T[]' => ['suffix', ['type' => 'array', 'items' => clipSourceSchema()]],

    'array<int, T>' => ['intKeyed', ['type' => 'array', 'items' => clipSourceSchema()]],

    'non-empty-list<T>' => ['nonEmpty', ['type' => 'array', 'items' => ['type' => 'integer']]],

    // An array key is int or string, so the member order is the only thing
    // that a client can rely on. That is a JSON array.
    'array<array-key, T>' => ['arrayKeyed', ['type' => 'array', 'items' => ['type' => 'string']]],

    // A string key serializes as a JSON object, and the value type becomes
    // additionalProperties. The nested list proves the reader recurses.
    'array<string, list<int>>' => ['mapOfLists', [
        'type' => 'object',
        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'integer']],
    ]],
]);

it('keeps the items of a nullable collection', function () {
    $properties = ObjectSchema::fromClass(CollectionShapes::class)['properties'];

    // `?array` with a `list<string>` tag is an array of strings or null. The
    // null belongs to the type of the property, and not to the type of a
    // member.
    expect($properties['nullable'])->toBe([
        'type' => ['array', 'null'],
        'items' => ['type' => 'string'],
    ]);
});

it('resolves a class that a grouped import names', function () {
    $properties = ObjectSchema::fromClass(GroupedImports::class)['properties'];

    // A grouped import brings in both names, and the second one under an
    // alias. The reader applies the same rules as PHP.
    expect($properties['grouped']['items']['properties'])->toBe(['alpha' => ['type' => 'string']])
        ->and($properties['aliased']['items']['properties'])->toBe(['beta' => ['type' => 'integer']]);
});

it('states no items when it cannot read the member type', function () {
    $properties = ObjectSchema::fromClass(CollectionShapes::class)['properties'];

    // `list<int|string>` states two member shapes, and `Collection<int, T>` is
    // a container that the package does not model. Both keep the declared
    // type, which is all that the package can state without guessing.
    expect($properties['mixedMembers'])->toBe(['type' => 'array'])
        ->and($properties['unmodelled'])->toBe(['type' => 'array']);
});
