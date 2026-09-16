<?php

namespace Square1\Mpp\Discovery;

/**
 * Translates a Laravel validation rule set into the JSON Schema the discovery
 * draft asks each payable operation to publish as its input schema.
 *
 * The draft says operations SHOULD declare an input schema, and that clients
 * and registries MAY flag one that does not as "schema-missing". An application
 * that validates with a `FormRequest` has already written that schema — in
 * Laravel's words rather than JSON Schema's — so the package reads it there
 * instead of asking for it twice. A rule set and a schema that are maintained
 * separately drift, and the published one is the one nobody runs.
 *
 * The translation is deliberately partial. It covers the rules that describe the
 * SHAPE of a request — types, requiredness, bounds, enumerations, nesting — and
 * ignores the rest. `exists`, `unique` and friends are database facts, not shape,
 * and a rule this class does not recognise simply contributes nothing rather than
 * producing a schema that claims more than Laravel enforces. An agent reading the
 * result learns what to send; it does not learn every reason a request may be
 * rejected, and the 422 remains authoritative in the same way the 402 does.
 */
final class ValidationSchema
{
    /**
     * Laravel type rules and the JSON Schema type each implies. Order matters
     * for the bound rules below: `min:3` means a length on a string, a value on
     * a number, and a count on an array.
     */
    private const TYPES = [
        'string' => 'string',
        'integer' => 'integer',
        'int' => 'integer',
        'numeric' => 'number',
        'decimal' => 'number',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'array' => 'array',
        'list' => 'array',
        'file' => 'string',
        'image' => 'string',
        'date' => 'string',
        'email' => 'string',
        'url' => 'string',
        'uuid' => 'string',
        'ulid' => 'string',
        'ip' => 'string',
        'ipv4' => 'string',
        'ipv6' => 'string',
        'json' => 'string',
    ];

    /** Rules that additionally pin a `format` on a string. */
    private const FORMATS = [
        'email' => 'email',
        'url' => 'uri',
        'uuid' => 'uuid',
        'date' => 'date-time',
        'ipv4' => 'ipv4',
        'ipv6' => 'ipv6',
        'file' => 'binary',
        'image' => 'binary',
    ];

    /**
     * Convert a `FormRequest::rules()` return value into a JSON Schema object,
     * or null when nothing in it described a shape.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>|null
     */
    public static function fromRules(array $rules): ?array
    {
        $tree = [];

        foreach ($rules as $field => $fieldRules) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            self::plant($tree, explode('.', $field), self::normalise($fieldRules));
        }

        $schema = self::compile($tree);

        return ($schema['properties'] ?? []) === [] ? null : $schema;
    }

    /**
     * Flatten one field's rules to a list of strings. Rule objects (`Rule::in()`,
     * `Password::min()`, an enum rule) stringify when they can and are dropped
     * when they cannot — a rule the package cannot read describes nothing, which
     * is the honest outcome.
     *
     * @return list<string>
     */
    private static function normalise(mixed $rules): array
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        $flat = [];

        foreach ((array) $rules as $rule) {
            if (is_string($rule)) {
                $flat[] = $rule;

                continue;
            }

            if (is_object($rule) && method_exists($rule, '__toString')) {
                $flat[] = (string) $rule;
            }
        }

        return array_values(array_filter(array_map('trim', $flat), fn (string $r) => $r !== ''));
    }

    /**
     * Place one field's rules in the tree at its dotted path. A `*` segment is
     * an array's items (`tags.*`), anything else a nested object's property
     * (`meta.name`); a path can mix them (`items.*.sku`).
     *
     * @param  array<string, mixed>  $tree
     * @param  list<string>  $path
     * @param  list<string>  $rules
     */
    private static function plant(array &$tree, array $path, array $rules): void
    {
        $segment = array_shift($path);
        $node = &$tree[$segment];

        if (! is_array($node)) {
            $node = ['rules' => [], 'children' => []];
        }

        if ($path === []) {
            $node['rules'] = array_values(array_unique([...$node['rules'], ...$rules]));

            return;
        }

        // A field with children is an object (or an array, when the next
        // segment is `*`) whether or not it carries a rule saying so.
        self::plant($node['children'], $path, $rules);
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    private static function compile(array $tree): array
    {
        $properties = [];
        $required = [];

        foreach ($tree as $name => $node) {
            if ($name === '*') {
                continue;
            }

            $properties[$name] = self::node($node);

            if (self::isRequired($node)) {
                $required[] = $name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  array{rules: list<string>, children: array<string, mixed>}  $node
     * @return array<string, mixed>
     */
    private static function node(array $node): array
    {
        $rules = $node['rules'];
        $children = $node['children'];
        $items = $children['*'] ?? null;

        // The children decide the type when the rules do not: a `*` child is an
        // array's items, any other child a property of an object.
        $type = self::type($rules) ?? match (true) {
            $items !== null => 'array',
            $children !== [] => 'object',
            default => null,
        };

        $schema = $type === null ? [] : ['type' => $type];

        if ($type !== null && self::hasRule($rules, 'nullable')) {
            $schema['type'] = [$type, 'null'];
        }

        if (($format = self::format($rules)) !== null) {
            $schema['format'] = $format;
        }

        $schema += self::bounds($rules, $type);

        if (($enum = self::enum($rules)) !== null) {
            $schema['enum'] = $enum;
        }

        if (($pattern = self::pattern($rules)) !== null) {
            $schema['pattern'] = $pattern;
        }

        if ($items !== null) {
            $schema['items'] = self::node($items);
        }

        $nested = array_filter($children, fn (string $key) => $key !== '*', ARRAY_FILTER_USE_KEY);

        if ($nested !== []) {
            $compiled = self::compile($nested);

            // compile() re-states the type; the node's own reading of it wins,
            // so a `nullable` object keeps its null branch.
            $schema['type'] ??= 'object';
            $schema['properties'] = $compiled['properties'];

            if (isset($compiled['required'])) {
                $schema['required'] = $compiled['required'];
            }
        }

        return $schema;
    }

    /**
     * Whether a field must be present. `required` on the field says so, and so
     * does `required` on anything nested inside it: a rule set that requires
     * `watermark.text` rejects a request that omits `watermark` altogether, so
     * a schema that called `watermark` optional would accept bodies Laravel
     * does not. An item rule (`tags.*`) is not such a case — it constrains the
     * items of an array that is there, and says nothing about whether it is.
     *
     * @param  array{rules: list<string>, children: array<string, mixed>}  $node
     */
    private static function isRequired(array $node): bool
    {
        if (self::hasRule($node['rules'], 'required')) {
            return true;
        }

        foreach ($node['children'] as $name => $child) {
            if ($name !== '*' && self::isRequired($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $rules
     */
    private static function type(array $rules): ?string
    {
        foreach ($rules as $rule) {
            $name = self::name($rule);

            if (isset(self::TYPES[$name])) {
                return self::TYPES[$name];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $rules
     */
    private static function format(array $rules): ?string
    {
        foreach ($rules as $rule) {
            $name = self::name($rule);

            if (isset(self::FORMATS[$name])) {
                return self::FORMATS[$name];
            }
        }

        return null;
    }

    /**
     * `min`, `max`, `between` and `size` all mean something different per type:
     * a length on a string, a value on a number, a count on an array. Without a
     * type rule there is nothing to attach them to, so they are dropped rather
     * than guessed at.
     *
     * @param  list<string>  $rules
     * @return array<string, int|float>
     */
    private static function bounds(array $rules, ?string $type): array
    {
        [$minKey, $maxKey] = match ($type) {
            'string' => ['minLength', 'maxLength'],
            'integer', 'number' => ['minimum', 'maximum'],
            'array' => ['minItems', 'maxItems'],
            default => [null, null],
        };

        if ($minKey === null) {
            return [];
        }

        $numeric = fn (string $value): int|float => $type === 'number' && str_contains($value, '.')
            ? (float) $value
            : (int) $value;

        $bounds = [];

        foreach ($rules as $rule) {
            $name = self::name($rule);
            $args = self::args($rule);

            if ($args === []) {
                continue;
            }

            match ($name) {
                'min' => $bounds[$minKey] = $numeric($args[0]),
                'max' => $bounds[$maxKey] = $numeric($args[0]),
                'size' => $bounds = [$minKey => $numeric($args[0]), $maxKey => $numeric($args[0])],
                'between' => $bounds = [
                    $minKey => $numeric($args[0]),
                    $maxKey => $numeric($args[1] ?? $args[0]),
                ],
                default => null,
            };
        }

        return $bounds;
    }

    /**
     * @param  list<string>  $rules
     * @return list<string>|null
     */
    private static function enum(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (self::name($rule) !== 'in') {
                continue;
            }

            // `in:"a,b",c` quotes values containing commas, exactly as
            // Rule::in() emits them.
            $values = array_map(fn (string $v) => trim(trim($v), '"'), self::args($rule));
            $values = array_values(array_filter($values, fn (string $v) => $v !== ''));

            if ($values !== []) {
                return $values;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $rules
     */
    private static function pattern(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (self::name($rule) !== 'regex') {
                continue;
            }

            // The rule carries a PCRE literal (`/^[a-z]+$/i`). JSON Schema
            // patterns are ECMA-262 source with no delimiters and no flags, so
            // only a plain, unflagged expression survives the trip; anything
            // else would publish a pattern that means something different from
            // the one Laravel enforces.
            $pcre = substr($rule, strlen('regex:'));
            $delimiter = $pcre[0] ?? '';
            $end = strrpos($pcre, $delimiter);

            if ($delimiter === '' || $end === false || $end === 0 || $end !== strlen($pcre) - 1) {
                continue;
            }

            return substr($pcre, 1, $end - 1);
        }

        return null;
    }

    /**
     * @param  list<string>  $rules
     */
    private static function hasRule(array $rules, string $needle): bool
    {
        foreach ($rules as $rule) {
            if (self::name($rule) === $needle) {
                return true;
            }
        }

        return false;
    }

    private static function name(string $rule): string
    {
        return strtolower(strtok($rule, ':') ?: $rule);
    }

    /**
     * @return list<string>
     */
    private static function args(string $rule): array
    {
        $colon = strpos($rule, ':');

        if ($colon === false) {
            return [];
        }

        return array_values(array_map('trim', explode(',', substr($rule, $colon + 1))));
    }
}
