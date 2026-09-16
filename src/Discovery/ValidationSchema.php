<?php

namespace Square1\Mpp\Discovery;

/**
 * Translates a Laravel validation rule set into a JSON Schema.
 *
 * The discovery draft asks each payable operation to publish that schema as its
 * input schema. The draft states that an operation SHOULD declare an input
 * schema, and that clients and registries MAY mark an operation without one as
 * "schema-missing".
 *
 * An application that validates with a `FormRequest` has already written the
 * schema, in the terms of Laravel and not of JSON Schema. The package therefore
 * reads it there and does not ask for it a second time. A rule set and a schema
 * that a site owner maintains separately become different, and the application
 * runs only the rule set.
 *
 * The translation is deliberately partial. It covers the rules that describe
 * the SHAPE of a request: types, requiredness, bounds, enumerations and
 * nesting. It ignores the other rules. `exists` and `unique` state database
 * facts, not shape. A rule that this class does not recognise contributes
 * nothing. The class does not produce a schema that claims more than Laravel
 * enforces.
 *
 * An agent that reads the result learns what to send. It does not learn every
 * reason for which the application can reject a request. The 422 response stays
 * authoritative, in the same way as the 402 response.
 */
final class ValidationSchema
{
    /**
     * The Laravel type rules, and the JSON Schema type that each one implies.
     *
     * The type also settles the bound rules below. `min:3` is a length on a
     * string, a value on a number, and a count on an array.
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

    /** The rules that also set a `format` on a string. */
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
     * Converts the return value of `FormRequest::rules()` into a JSON Schema
     * object.
     *
     * The method returns null when no rule in the set described a shape.
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

        $body = self::compile($tree);

        return $body['properties'] === [] ? null : ['type' => 'object'] + $body;
    }

    /**
     * Flattens the rules of one field to a list of strings.
     *
     * A rule object, such as `Rule::in()`, `Password::min()` or an enum rule,
     * becomes a string when it can. The method drops a rule object that cannot
     * become a string. A rule that the package cannot read describes nothing,
     * and the method states nothing about it.
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
     * Places the rules of one field in the tree, at its dotted path.
     *
     * A `*` segment is the items of an array, as in `tags.*`. Any other segment
     * is a property of a nested object, as in `meta.name`. A path can contain
     * both forms, as in `items.*.sku`.
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

        // A field that has children is an object, or an array when the next
        // segment is `*`. This is true whether or not the field carries a rule
        // that states the type.
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

        $schema = ['properties' => $properties];

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

        // The children settle the type when the rules do not. A `*` child is
        // the items of an array. Any other child is a property of an object.
        $type = self::lookup($rules, self::TYPES) ?? match (true) {
            $items !== null => 'array',
            $children !== [] => 'object',
            default => null,
        };

        $schema = $type === null ? [] : ['type' => $type];

        if ($type !== null && self::hasRule($rules, 'nullable')) {
            $schema['type'] = [$type, 'null'];
        }

        if (($format = self::lookup($rules, self::FORMATS)) !== null) {
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
            $schema += self::compile($nested);
        }

        return $schema;
    }

    /**
     * Reports whether a field must be present.
     *
     * `required` on the field states this. `required` on a field nested inside
     * it states this too. A rule set that requires `watermark.text` rejects a
     * request that omits `watermark`. A schema that marked `watermark` optional
     * would therefore accept a body that Laravel rejects.
     *
     * An item rule such as `tags.*` is different. It constrains the items of an
     * array that is present, and states nothing about whether the array must be
     * present.
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
     * Returns the value for the first rule that appears in the given table.
     *
     * The precedence is deliberate. `date` types a field `string` and formats
     * it `date-time`. A rule set that names two types already contradicts
     * itself, so the first type is a correct answer and a stable one.
     *
     * @param  list<string>  $rules
     * @param  array<string, string>  $table
     */
    private static function lookup(array $rules, array $table): ?string
    {
        foreach ($rules as $rule) {
            $name = self::name($rule);

            if (isset($table[$name])) {
                return $table[$name];
            }
        }

        return null;
    }

    /**
     * Returns the bounds that the rules state.
     *
     * `min`, `max`, `between` and `size` each mean something different for each
     * type: a length on a string, a value on a number, and a count on an array.
     * Without a type rule there is nothing to apply them to. The method
     * therefore drops them and does not infer a type.
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

            // `in:"a,b",c` puts quotation marks around a value that contains a
            // comma. Rule::in() writes the values in that form.
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

            // The rule carries a PCRE literal such as `/^[a-z]+$/i`. A JSON
            // Schema pattern is ECMA-262 source, with no delimiters and no
            // flags. Only an expression without flags can make the conversion.
            // Any other expression would publish a pattern with a different
            // meaning from the pattern that Laravel enforces.
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
