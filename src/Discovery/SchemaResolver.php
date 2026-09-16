<?php

namespace Square1\Mpp\Discovery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

/**
 * Converts what a site owner stated as a request or response schema into the
 * OpenAPI fragment that the discovery document publishes.
 *
 * The draft asks for input and output schemas. It does not state where they
 * come from. This class therefore accepts the three forms that a Laravel
 * application has available:
 *
 *   - a JSON Schema array, written inline;
 *   - a full OpenAPI `requestBody` or response array, for anything that the
 *     short form cannot express, such as several media types, `$ref`, or
 *     examples;
 *   - a class name. The class is a `FormRequest`, whose `rules()` already
 *     describe the input, or a class of your own with a `schema()` method that
 *     returns an array.
 *
 * The class logs a schema that it cannot resolve, and leaves it out. The
 * document stays advisory. An incorrect schema reference costs the operation
 * its schema. It must not cost the operation its entry, and it must never stop
 * the route from charging.
 */
final class SchemaResolver
{
    /**
     * The schemas that the class has derived for this document, by class name.
     *
     * Several routes commonly share one FormRequest. One route is also several
     * operations, once you count its verbs and its optional-parameter path
     * variants. Without this cache, each operation builds the class again and
     * parses its rule set again, and each one reaches the same answer.
     *
     * @var array<class-string, array<string, mixed>|null>
     */
    private array $derived = [];

    /**
     * Returns the `requestBody` for an operation, or null when nothing
     * described one.
     *
     * @param  class-string|array<string, mixed>|null  $request
     * @return array<string, mixed>|null
     */
    public function requestBody(string|array|null $request): ?array
    {
        $stated = $this->resolve($request, 'request');

        if ($stated === null) {
            return null;
        }

        if (isset($stated['content'])) {
            return $stated;
        }

        $body = ['content' => ['application/json' => ['schema' => $stated]]];

        // An operation cannot run without a body that has required
        // properties. That is the meaning of `required` in OpenAPI here.
        if (($stated['required'] ?? []) !== []) {
            $body = ['required' => true] + $body;
        }

        return $body;
    }

    /**
     * Returns the `responses` entries that a stated output schema contributes,
     * keyed by status code.
     *
     * A plain schema documents the 200 response. A map keyed by status code
     * documents each response that it names. An operation declares its error
     * shapes and its success shape in that way.
     *
     * @param  class-string|array<string, mixed>|null  $response
     * @return array<string, array<string, mixed>>
     */
    public function responses(string|array|null $response): array
    {
        $stated = $this->resolve($response, 'response');

        if ($stated === null) {
            return [];
        }

        if (! $this->isStatusMap($stated)) {
            return ['200' => $this->response($stated, 'Successful response')];
        }

        $responses = [];

        foreach ($stated as $status => $schema) {
            $responses[(string) $status] = $this->response(
                is_array($schema) ? $schema : [],
                ((string) $status)[0] === '2' ? 'Successful response' : 'Error response',
            );
        }

        return $responses;
    }

    /**
     * Builds one response object.
     *
     * The method takes an entry as written when the entry is already a response
     * object, that is when it has its own description or its own content. Any
     * other entry is a JSON Schema, and the method wraps it.
     *
     * @param  array<string, mixed>  $stated
     * @return array<string, mixed>
     */
    private function response(array $stated, string $fallbackDescription): array
    {
        if (isset($stated['content']) || isset($stated['description'])) {
            // The stated description wins. The fallback fills only a response
            // that named its content and did not describe it. OpenAPI requires
            // a description on every response object.
            return $stated + ['description' => $fallbackDescription];
        }

        return [
            'description' => $fallbackDescription,
            'content' => ['application/json' => ['schema' => $stated]],
        ];
    }

    /**
     * Reports whether every key is a three-digit status code or the OpenAPI
     * `default` key.
     *
     * This test separates a map of responses from a single schema whose
     * properties have numbers for names.
     *
     * @param  array<array-key, mixed>  $stated
     */
    private function isStatusMap(array $stated): bool
    {
        if ($stated === []) {
            return false;
        }

        foreach (array_keys($stated) as $key) {
            if ((string) $key !== 'default' && preg_match('/^[1-5]\d{2}$/', (string) $key) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  class-string|array<string, mixed>|null  $stated
     * @return array<string, mixed>|null
     */
    private function resolve(string|array|null $stated, string $noun): ?array
    {
        if (is_array($stated)) {
            return $stated === [] ? null : $stated;
        }

        if ($stated === null || $stated === '') {
            return null;
        }

        if (! class_exists($stated)) {
            Log::warning("[mpp] Discovery could not resolve the {$noun} schema '{$stated}': no such class.");

            return null;
        }

        if (array_key_exists($stated, $this->derived)) {
            return $this->derived[$stated];
        }

        try {
            $schema = $this->fromClass($stated);
        } catch (\Throwable $e) {
            // This is commonly a FormRequest whose rules() reads a request
            // that the package did not give it. The operation loses its schema
            // and keeps every other field. The log names the class to
            // examine.
            Log::warning("[mpp] Discovery could not derive the {$noun} schema from '{$stated}': ".$e->getMessage());

            return $this->derived[$stated] = null;
        }

        return $this->derived[$stated] = ($schema === [] ? null : $schema);
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>|null
     */
    private function fromClass(string $class): ?array
    {
        // The method builds the class WITHOUT the container, on purpose. The
        // container fires Laravel's `ValidatesWhenResolved` hook for a
        // FormRequest. That hook runs the validator, and the validator fails.
        // Discovery needs only the rule set that the class declares.
        $instance = new $class;

        if (method_exists($instance, 'schema')) {
            $schema = $instance->schema();

            return is_array($schema) ? $schema : null;
        }

        // The test is for a FormRequest, not for any class with a `rules()`
        // method. `rules()` is a common method name. To read it from a policy
        // or a value object would publish data that was never a request shape.
        // A class of your own declares a schema with `schema()`.
        if ($instance instanceof FormRequest) {
            return ValidationSchema::fromRules((array) $instance->rules());
        }

        Log::warning("[mpp] Discovery could not read a schema from '{$class}': it is not a FormRequest and has no schema() method.");

        return null;
    }
}
