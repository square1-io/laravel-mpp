<?php

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Square1\Mpp\Tests\Fakes\DocumentStage;

/**
 * The discovery draft publishes JSON Schemas for both of its extensions and
 * asks tooling authors to validate against them. These are those schemas,
 * copied verbatim out of `draft-payment-discovery-01` (appendices "JSON Schema
 * for x-payment-info" and "JSON Schema for x-service-info") into
 * tests/Fixtures, and this file points them at the document the package
 * actually generates.
 *
 * Every other discovery test asserts what the package MEANT to publish. These
 * assert that what it publishes is legal, which is a different question and the
 * one a registry will be asking. Both extension schemas are
 * `additionalProperties: false`, so this is also the test that catches a
 * well-meant extra key: mpp.tl's own document carries `recipient` on each offer
 * and `price`/`protocols` beside `offers`, and so matches neither branch of the
 * schema its extension is defined by.
 */
function validateAgainst(string $fixture, mixed $value): void
{
    $schema = json_decode(file_get_contents(__DIR__.'/../Fixtures/'.$fixture), false, 512, JSON_THROW_ON_ERROR);

    // Objects, not associative arrays: JSON Schema distinguishes them, and a
    // PHP array would arrive as one or the other by accident.
    $result = (new Validator)->validate(
        json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
        $schema,
    );

    $errors = $result->error() === null ? [] : (new ErrorFormatter)->format($result->error());

    expect($errors)->toBe([], 'Document fails '.$fixture.': '.json_encode($errors));
}

/**
 * @return list<array{string, array<string, mixed>}> path => operation
 */
function operationsIn(array $document): array
{
    $operations = [];

    foreach ($document['paths'] as $path => $verbs) {
        foreach ($verbs as $verb => $operation) {
            $operations[] = [strtoupper($verb).' '.$path, $operation];
        }
    }

    return $operations;
}

it('publishes an x-payment-info that validates against the draft schema', function () {
    $document = $this->get('/openapi.json')->json();

    $checked = 0;

    foreach (operationsIn($document) as [$label, $operation]) {
        if (! isset($operation['x-payment-info'])) {
            continue;
        }

        validateAgainst('x-payment-info.schema.json', $operation['x-payment-info']);
        $checked++;
    }

    // The loop passing vacuously would prove nothing.
    expect($checked)->toBeGreaterThan(10, "Only {$checked} payable operations were checked.");
});

it('publishes an x-service-info that validates against the draft schema', function () {
    config()->set('mpp.discovery.categories', ['media', 'compute']);
    config()->set('mpp.discovery.docs.homepage', '/');
    config()->set('mpp.discovery.docs.api_reference', 'https://example.test/reference');
    config()->set('mpp.discovery.docs.llms', '/llms.txt');

    $serviceInfo = $this->get('/openapi.json')->json('x-service-info');

    // The schema types every docs link `format: uri`, which is what makes the
    // relative forms above worth resolving rather than publishing as written.
    validateAgainst('x-service-info.schema.json', $serviceInfo);
});

it('validates every offer a rail-priced, dynamic or metered route produces', function () {
    // The offer shapes differ in ways the schema cares about: a resolver-priced
    // route emits `amount: null`, a misconfigured rail emits an offer with no
    // currency at all. Both have to stay legal.
    config()->set('mpp.methods.tempo.recipient', null);
    config()->set('mpp.accept', ['stripe', 'tempo']);

    foreach (operationsIn($this->get('/openapi.json')->json()) as [$label, $operation]) {
        if (isset($operation['x-payment-info'])) {
            validateAgainst('x-payment-info.schema.json', $operation['x-payment-info']);
        }
    }
});

it('declares every path parameter its path templates', function () {
    // OpenAPI requires a path template variable to have a matching parameter
    // object. This is what a `{month?}` published verbatim would fail.
    foreach ($this->get('/openapi.json')->json('paths') as $path => $verbs) {
        preg_match_all('/\{([^}]*)\}/', $path, $matches);

        foreach ($verbs as $verb => $operation) {
            $declared = array_column(
                array_filter($operation['parameters'] ?? [], fn (array $p) => $p['in'] === 'path'),
                'name'
            );

            expect($declared)->toBe($matches[1], "{$verb} {$path}");
        }
    }
});

it('keeps every operationId unique across the document', function () {
    $ids = [];

    foreach (operationsIn($this->get('/openapi.json')->json()) as [$label, $operation]) {
        if (isset($operation['operationId'])) {
            $ids[] = $operation['operationId'];
        }
    }

    expect($ids)->toBe(array_values(array_unique($ids)));
});

it('declares a 402 on every payable operation and on no free one', function () {
    config()->set('mpp.discovery.include', ['free/*']);

    $payable = 0;

    foreach (operationsIn($this->get('/openapi.json')->json()) as [$label, $operation]) {
        if (isset($operation['x-payment-info'])) {
            expect($operation['responses'])->toHaveKey('402');
            $payable++;

            continue;
        }

        expect($operation['responses'])->not->toHaveKey('402');
    }

    expect($payable)->toBeGreaterThan(10);
});

it('catches the document the pipeline breaks', function () {
    // The pipeline is the one place a site owner can make the document
    // non-conformant, and this is exactly how: a key that reads as useful and
    // that `additionalProperties: false` forbids. mpp.tl publishes `recipient`
    // on every offer today.
    config()->set('mpp.discovery.pipeline', [[DocumentStage::class, 'addRecipient']]);

    $info = $this->get('/openapi.json')->json('paths./clip.get.x-payment-info');

    $schema = json_decode(file_get_contents(__DIR__.'/../Fixtures/x-payment-info.schema.json'), false);
    $result = (new Validator)->validate(json_decode(json_encode($info), false), $schema);

    expect($result->isValid())->toBeFalse();
});
