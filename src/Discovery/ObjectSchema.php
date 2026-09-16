<?php

namespace Square1\Mpp\Discovery;

use BackedEnum;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Derives a JSON Schema from the types of a data object.
 *
 * This is the output counterpart of {@see ValidationSchema}. A FormRequest
 * states an input shape in its rules, and the package reads it. A response
 * object states an output shape in its property types, and the package reads
 * that in the same way. Neither one asks the site owner to write the shape a
 * second time.
 *
 * The class reads a public typed property, which includes a promoted
 * constructor property. A property is required when the class gives it no
 * default, because the caller must then supply it.
 *
 * Requiredness and nullability are separate questions, and JSON Schema keeps
 * them separate. `?string $session` with no default is a required key whose
 * value can be null. The class states it as `"type": ["string", "null"]` inside
 * `required`.
 *
 * A PHP `array` type does not name its member type, so the class reads the
 * docblock for it. {@see DocBlockTypes} supplies the text, and this class turns
 * `list<Scoreline>` into an `items` schema.
 *
 * The class states nothing that it cannot read:
 *
 *   - an untyped property, a union type and `mixed` carry no shape, so the
 *     schema leaves the property out;
 *   - an `array` property with no docblock states `type: array` and no
 *     `items`;
 *   - a pure enum has no value to serialize, so the schema leaves it out.
 *
 * A left-out property is undocumented, and the schema never sets
 * `additionalProperties: false`. An agent therefore reads the schema as
 * incomplete and not as a denial.
 *
 * The class describes the OBJECT, and not the JSON that the object serializes
 * to. {@see SchemaResolver::fromClass()} rejects a class that serializes
 * itself, because the two can differ.
 */
final class ObjectSchema
{
    /**
     * The depth at which the class stops.
     *
     * A cycle between two objects is already caught, because the class carries
     * the classes it is inside. This limit holds a deep tree of distinct
     * objects, which a client cannot usefully read either.
     */
    private const MAX_DEPTH = 5;

    /**
     * The docblock types that state a shape on their own.
     *
     * The keys cover the spellings that a docblock uses beside the PHP ones,
     * such as `non-empty-string` and `positive-int`. Each one narrows a type
     * that JSON Schema already has, and the schema keeps the wider type.
     */
    private const SCALARS = [
        'string' => ['type' => 'string'],
        'non-empty-string' => ['type' => 'string'],
        'class-string' => ['type' => 'string'],
        'numeric-string' => ['type' => 'string'],
        'int' => ['type' => 'integer'],
        'integer' => ['type' => 'integer'],
        'positive-int' => ['type' => 'integer'],
        'negative-int' => ['type' => 'integer'],
        'float' => ['type' => 'number'],
        'double' => ['type' => 'number'],
        'bool' => ['type' => 'boolean'],
        'boolean' => ['type' => 'boolean'],
        'true' => ['type' => 'boolean'],
        'false' => ['type' => 'boolean'],
        'array' => ['type' => 'array'],
        'object' => ['type' => 'object'],
        'null' => ['type' => 'null'],
    ];

    /**
     * The docblock types that state no shape.
     *
     * The class stops at these rather than read them as class names.
     *
     * @var list<string>
     */
    private const UNTYPED = [
        'mixed', 'iterable', 'callable', 'resource', 'void', 'never',
        'self', 'static', 'parent', '$this', 'scalar', 'array-key', 'key-of', 'value-of',
    ];

    /**
     * @param  class-string  $class
     * @return array<string, mixed>|null null when nothing in the class states a shape
     */
    public static function fromClass(string $class): ?array
    {
        return self::build($class, []);
    }

    /**
     * @param  class-string  $class
     * @param  list<class-string>  $enclosing  the classes that this one is inside
     * @return array<string, mixed>|null
     */
    private static function build(string $class, array $enclosing): ?array
    {
        if (in_array($class, $enclosing, true) || count($enclosing) >= self::MAX_DEPTH) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isEnum()) {
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $schema = self::forProperty($property, $reflection, [...$enclosing, $class]);

            if ($schema === null) {
                continue;
            }

            $name = $property->getName();
            $properties[$name] = $schema;

            // A default is the one signal that the caller can leave the
            // property out. A type that allows null is NOT such a signal: a
            // null value and an absent key are different things, and JSON
            // Schema states them in different keywords.
            if (! self::hasDefault($property)) {
                $required[] = $name;
            }
        }

        if ($properties === []) {
            return null;
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  list<class-string>  $enclosing
     * @return array<string, mixed>|null
     */
    private static function forProperty(ReflectionProperty $property, ReflectionClass $context, array $enclosing): ?array
    {
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        // The PHP type of an array names no member type, so the docblock is
        // the only statement of one. It outranks the declared type, which can
        // say no more than `array`.
        $schema = self::holdsMembers($type)
            ? self::fromDocBlock($property, $context, $enclosing)
            : null;

        $schema ??= self::forType($type, $enclosing);

        if ($schema === null) {
            return null;
        }

        return $type->allowsNull() ? self::orNull($schema) : $schema;
    }

    private static function holdsMembers(ReflectionNamedType $type): bool
    {
        return in_array($type->getName(), ['array', 'iterable'], true);
    }

    /**
     * @param  list<class-string>  $enclosing
     * @return array<string, mixed>|null
     */
    private static function fromDocBlock(ReflectionProperty $property, ReflectionClass $context, array $enclosing): ?array
    {
        $written = DocBlockTypes::forProperty($property);

        if ($written === null) {
            return null;
        }

        $schema = self::fromExpression($written, $context, $enclosing);

        // A docblock that repeats the declared type, as `array`, adds nothing.
        // The caller then falls back to the declared type, which reaches the
        // same answer.
        return isset($schema['items']) || isset($schema['additionalProperties']) ? $schema : null;
    }

    /**
     * @param  list<class-string>  $enclosing
     * @return array<string, mixed>|null
     */
    private static function forType(ReflectionNamedType $type, array $enclosing): ?array
    {
        $name = $type->getName();

        return match ($name) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array', 'iterable' => ['type' => 'array'],
            default => $type->isBuiltin() ? null : self::forClass($name, $enclosing),
        };
    }

    /**
     * @param  list<class-string>  $enclosing
     * @return array<string, mixed>|null
     */
    private static function forClass(string $name, array $enclosing): ?array
    {
        if (! class_exists($name) && ! interface_exists($name) && ! enum_exists($name)) {
            return null;
        }

        // A backed enum states its own members. This is the one place where the
        // package can publish an `enum` with nothing for the site owner to
        // write.
        if (is_subclass_of($name, BackedEnum::class)) {
            $values = array_column($name::cases(), 'value');

            return [
                'type' => is_int($values[0] ?? null) ? 'integer' : 'string',
                'enum' => $values,
            ];
        }

        if (is_a($name, DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        // A nested object can state its own schema, and that statement outranks
        // what its types imply.
        if (is_subclass_of($name, ProvidesSchema::class)) {
            $stated = $name::schema();

            return $stated === [] ? null : $stated;
        }

        return self::build($name, $enclosing);
    }

    /**
     * Reads a type as a docblock writes it.
     *
     * @param  list<class-string>  $enclosing
     * @return array<string, mixed>|null
     */
    private static function fromExpression(string $expression, ReflectionClass $context, array $enclosing): ?array
    {
        $expression = trim($expression);

        if ($expression === '') {
            return null;
        }

        if (str_starts_with($expression, '?')) {
            $schema = self::fromExpression(substr($expression, 1), $context, $enclosing);

            return $schema === null ? null : self::orNull($schema);
        }

        $members = self::split($expression, '|');

        if (count($members) > 1) {
            $stated = array_values(array_filter($members, fn (string $m) => strtolower(trim($m)) !== 'null'));

            // `Foo|null` is a nullable Foo. Any other union states several
            // shapes, and the class publishes none of them rather than pick.
            if (count($stated) !== 1) {
                return null;
            }

            $schema = self::fromExpression($stated[0], $context, $enclosing);

            return $schema === null ? null : self::orNull($schema);
        }

        if (str_ends_with($expression, '[]')) {
            return self::listOf(self::fromExpression(substr($expression, 0, -2), $context, $enclosing));
        }

        if (preg_match('/^([\\\\\w-]+)\s*<(.*)>$/s', $expression, $match) === 1) {
            return self::fromGeneric(
                strtolower(ltrim($match[1], '\\')),
                self::split($match[2], ','),
                $context,
                $enclosing,
            );
        }

        return self::fromName($expression, $context, $enclosing);
    }

    /**
     * Reads a generic type, such as `list<Scoreline>` or `array<string, int>`.
     *
     * @param  list<string>  $arguments
     * @param  list<class-string>  $enclosing
     * @return array<string, mixed>|null
     */
    private static function fromGeneric(string $name, array $arguments, ReflectionClass $context, array $enclosing): ?array
    {
        $name = match ($name) {
            'non-empty-list' => 'list',
            'non-empty-array' => 'array',
            default => $name,
        };

        if (! in_array($name, ['list', 'array', 'iterable'], true) || $arguments === []) {
            // A generic of another kind, such as a collection class, states a
            // container that the package does not model.
            return null;
        }

        if ($name === 'list' || count($arguments) === 1) {
            return self::listOf(self::fromExpression($arguments[0], $context, $enclosing));
        }

        $value = self::fromExpression($arguments[1], $context, $enclosing);

        // A string key is a JSON object, and any other key is a JSON array.
        // `array<string, Scoreline>` and `list<Scoreline>` serialize
        // differently, and the schema has to say which one this is.
        if (strtolower(trim($arguments[0])) === 'string') {
            return $value === null
                ? ['type' => 'object']
                : ['type' => 'object', 'additionalProperties' => $value];
        }

        return self::listOf($value);
    }

    /**
     * @param  array<string, mixed>|null  $items
     * @return array<string, mixed>
     */
    private static function listOf(?array $items): array
    {
        return $items === null ? ['type' => 'array'] : ['type' => 'array', 'items' => $items];
    }

    /**
     * @param  list<class-string>  $enclosing
     * @return array<string, mixed>|null
     */
    private static function fromName(string $name, ReflectionClass $context, array $enclosing): ?array
    {
        $key = strtolower(trim($name));

        if (isset(self::SCALARS[$key])) {
            return self::SCALARS[$key];
        }

        if (in_array($key, self::UNTYPED, true)) {
            return null;
        }

        $class = DocBlockTypes::className($name, $context);

        return $class === null ? null : self::forClass($class, $enclosing);
    }

    /**
     * Splits a type expression at a delimiter, outside of any generic.
     *
     * `array<string, list<int>>` has one top-level comma, and the inner one
     * belongs to the nested generic.
     *
     * @return list<string>
     */
    private static function split(string $expression, string $delimiter): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($expression) as $character) {
            if ($character === '<' || $character === '{') {
                $depth++;
            } elseif ($character === '>' || $character === '}') {
                $depth--;
            } elseif ($character === $delimiter && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), fn (string $p) => $p !== ''));
    }

    /**
     * Adds null to the type of a schema.
     *
     * OpenAPI 3.1 is JSON Schema, so a null is a member of the type and not a
     * flag beside it.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function orNull(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            $schema['type'] = [$type, 'null'];
        } elseif (is_array($type) && ! in_array('null', $type, true)) {
            $schema['type'] = [...$type, 'null'];
        }

        return $schema;
    }

    /**
     * Reports whether the class gives the property a value of its own.
     *
     * A promoted property holds its default on the constructor parameter, and
     * not on the property, so hasDefaultValue() alone answers only half of the
     * question.
     */
    private static function hasDefault(ReflectionProperty $property): bool
    {
        if ($property->hasDefaultValue()) {
            return true;
        }

        if (! $property->isPromoted()) {
            return false;
        }

        $constructor = $property->getDeclaringClass()->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property->getName()) {
                return $parameter->isDefaultValueAvailable();
            }
        }

        return false;
    }
}
