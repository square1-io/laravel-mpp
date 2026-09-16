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
 * constructor property. A property is required when its type forbids null and
 * the class gives it no default.
 *
 * The class states nothing that it cannot read:
 *
 *   - an untyped property, a union type and `mixed` carry no shape, so the
 *     schema leaves the property out;
 *   - an `array` property states `type: array` and no `items`, because the
 *     type of a PHP array does not name its member type;
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
            $type = $property->getType();

            if ($property->isStatic() || ! $type instanceof ReflectionNamedType) {
                continue;
            }

            $schema = self::forType($type, [...$enclosing, $class]);

            if ($schema === null) {
                continue;
            }

            $name = $property->getName();
            $properties[$name] = $schema;

            if (! $type->allowsNull() && ! self::hasDefault($property)) {
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
    private static function forType(ReflectionNamedType $type, array $enclosing): ?array
    {
        $name = $type->getName();

        $schema = match ($name) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array', 'iterable' => ['type' => 'array'],
            default => $type->isBuiltin() ? null : self::forClass($name, $enclosing),
        };

        if ($schema === null) {
            return null;
        }

        // OpenAPI 3.1 is JSON Schema, so a null is a member of the type and not
        // a flag beside it.
        if ($type->allowsNull() && is_string($schema['type'] ?? null)) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return $schema;
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
