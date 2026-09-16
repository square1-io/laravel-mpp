<?php

use Square1\Mpp\Discovery\ValidationSchema;

it('reads types, formats and requiredness off a rule set', function () {
    $schema = ValidationSchema::fromRules([
        'name' => 'required|string',
        'age' => 'integer',
        'price' => 'numeric',
        'active' => 'boolean',
        'email' => 'required|email',
        'website' => 'url',
        'id' => 'uuid',
        'when' => 'date',
    ]);

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['name', 'email'])
        ->and($schema['properties']['age'])->toBe(['type' => 'integer'])
        ->and($schema['properties']['price'])->toBe(['type' => 'number'])
        ->and($schema['properties']['active'])->toBe(['type' => 'boolean'])
        ->and($schema['properties']['email'])->toBe(['type' => 'string', 'format' => 'email'])
        ->and($schema['properties']['website'])->toBe(['type' => 'string', 'format' => 'uri'])
        ->and($schema['properties']['id'])->toBe(['type' => 'string', 'format' => 'uuid'])
        ->and($schema['properties']['when'])->toBe(['type' => 'string', 'format' => 'date-time']);
});

it('reads a bound as a length, a value or a count, depending on the type', function () {
    $schema = ValidationSchema::fromRules([
        'title' => 'string|min:3|max:100',
        'quantity' => 'integer|between:1,10',
        'items' => 'array|size:4',
        'unknown' => 'min:3',
    ]);

    expect($schema['properties']['title'])->toBe(['type' => 'string', 'minLength' => 3, 'maxLength' => 100])
        ->and($schema['properties']['quantity'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 10])
        ->and($schema['properties']['items'])->toBe(['type' => 'array', 'minItems' => 4, 'maxItems' => 4])
        // Without a type there is nothing for a bound to mean, so it is dropped
        // rather than guessed at.
        ->and($schema['properties']['unknown'])->toBe([]);
});

it('accepts rules as an array as well as a pipe-separated string', function () {
    $schema = ValidationSchema::fromRules([
        'format' => ['required', 'string', 'in:mp4,webm'],
    ]);

    expect($schema['properties']['format'])
        ->toBe(['type' => 'string', 'enum' => ['mp4', 'webm']])
        ->and($schema['required'])->toBe(['format']);
});

it('reads a nullable field as a union with null', function () {
    $schema = ValidationSchema::fromRules(['caption' => 'nullable|string']);

    expect($schema['properties']['caption'])->toBe(['type' => ['string', 'null']]);
});

it('reads a dotted rule set as nested objects and array items', function () {
    $schema = ValidationSchema::fromRules([
        'tags' => 'array',
        'tags.*' => 'string|max:20',
        'lines' => 'required|array',
        'lines.*.sku' => 'required|string',
        'lines.*.qty' => 'integer|min:1',
        'meta.author.name' => 'string',
    ]);

    expect($schema['properties']['tags'])
        ->toBe(['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 20]]);

    expect($schema['properties']['lines'])->toBe([
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string'],
                'qty' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['sku'],
        ],
    ]);

    // A field with children is an object whether or not a rule said so.
    expect($schema['properties']['meta']['properties']['author']['properties']['name'])
        ->toBe(['type' => 'string']);
});

it('requires a parent whose child is required', function () {
    // Laravel rejects a body with no `watermark` at all when `watermark.text`
    // is required, so a schema calling `watermark` optional would accept more
    // than the validator does.
    $schema = ValidationSchema::fromRules(['watermark.text' => 'required|string']);

    expect($schema['required'])->toBe(['watermark'])
        ->and($schema['properties']['watermark']['required'])->toBe(['text']);
});

it('does not require an array because its items carry rules', function () {
    // `tags.*` constrains the items of an array that is present; it says
    // nothing about whether it has to be.
    $schema = ValidationSchema::fromRules(['tags' => 'array', 'tags.*' => 'required|string']);

    expect($schema)->not->toHaveKey('required');
});

it('carries a plain regex across as a pattern, and drops one it cannot', function () {
    $schema = ValidationSchema::fromRules([
        'slug' => 'string|regex:/^[a-z-]+$/',
        // JSON Schema patterns have no flags, so a flagged expression would
        // publish a constraint that means something other than the enforced one.
        'code' => 'string|regex:/^[A-Z]+$/i',
    ]);

    expect($schema['properties']['slug']['pattern'])->toBe('^[a-z-]+$')
        ->and($schema['properties']['code'])->not->toHaveKey('pattern');
});

it('ignores rules that describe something other than the shape', function () {
    $schema = ValidationSchema::fromRules([
        'email' => 'required|email|unique:users,email|exists:invites,email',
    ]);

    // A database fact is not a shape. The 422 stays authoritative for the rest,
    // exactly as the 402 does for price.
    expect($schema['properties']['email'])->toBe(['type' => 'string', 'format' => 'email']);
});

it('returns null for a rule set that describes no shape at all', function () {
    expect(ValidationSchema::fromRules([]))->toBeNull()
        ->and(ValidationSchema::fromRules([0 => 'required']))->toBeNull();
});
