<?php

namespace Square1\Mpp\Discovery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

/**
 * Turns whatever a site owner stated as a request or response schema into the
 * OpenAPI fragment the discovery document publishes.
 *
 * The draft asks for input and output schemas but says nothing about where they
 * come from, so this accepts the three things a Laravel application actually
 * has to hand:
 *
 *   - a JSON Schema array, written inline;
 *   - a full OpenAPI `requestBody` / response array, for anything the shorthand
 *     cannot express (several media types, `$ref`, examples);
 *   - a class name — a `FormRequest`, whose `rules()` already describe the
 *     input, or any class of your own with a `schema()` method returning an
 *     array.
 *
 * A schema that cannot be resolved is logged and left out. The document stays
 * advisory: a broken schema reference must cost the operation its schema, not
 * its listing, and never the route its ability to charge.
 */
final class SchemaResolver
{
    /**
     * Schemas already derived this document, by class name. One FormRequest is
     * commonly shared by several routes, and one route is several operations
     * once its verbs and optional-parameter path variants are counted — without
     * this, each of them re-instantiates the class and re-parses its rule set
     * to arrive at the same answer.
     *
     * @var array<class-string, array<string, mixed>|null>
     */
    private array $derived = [];

    /**
     * The `requestBody` for an operation, or null when nothing described one.
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

        // A schema with required properties is a body the operation cannot run
        // without, which is what OpenAPI's `required` means here.
        if (($stated['required'] ?? []) !== []) {
            $body = ['required' => true] + $body;
        }

        return $body;
    }

    /**
     * The `responses` entries a stated output schema contributes, keyed by
     * status code. A bare schema documents the 200; a map keyed by status code
     * documents each response it names, which is how an operation declares its
     * error shapes as well as its success one.
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
     * One response object. An entry that already looks like one (it describes
     * itself, or names its own content) is taken as written; anything else is a
     * JSON Schema to wrap.
     *
     * @param  array<string, mixed>  $stated
     * @return array<string, mixed>
     */
    private function response(array $stated, string $fallbackDescription): array
    {
        if (isset($stated['content']) || isset($stated['description'])) {
            // The stated description wins; the fallback only fills a response
            // that named its content without describing it (OpenAPI requires a
            // description on every response object).
            return $stated + ['description' => $fallbackDescription];
        }

        return [
            'description' => $fallbackDescription,
            'content' => ['application/json' => ['schema' => $stated]],
        ];
    }

    /**
     * True when every key is a three-digit status code (or the OpenAPI
     * `default`), which is what distinguishes a map of responses from a single
     * schema whose properties happen to be numbered.
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
            // Commonly a FormRequest whose rules() reads the request it was
            // never given. The operation loses its schema and keeps everything
            // else, and the log says which class to look at.
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
        // Built WITHOUT the container on purpose: resolving a FormRequest
        // through it fires Laravel's `ValidatesWhenResolved` hook, which would
        // run the validator — and fail — while all discovery wants is the rule
        // set the class declares.
        $instance = new $class;

        if (method_exists($instance, 'schema')) {
            $schema = $instance->schema();

            return is_array($schema) ? $schema : null;
        }

        // Narrowed to a FormRequest rather than anything with a `rules()`
        // method: `rules()` is a common enough name that reading it off a
        // policy or a value object would publish something that was never a
        // request shape. A class of your own says so with `schema()`.
        if ($instance instanceof FormRequest) {
            return ValidationSchema::fromRules((array) $instance->rules());
        }

        Log::warning("[mpp] Discovery could not read a schema from '{$class}': it is not a FormRequest and has no schema() method.");

        return null;
    }
}
