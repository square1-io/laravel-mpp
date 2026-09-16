<?php

namespace Square1\Mpp\Discovery;

use Closure;
use Illuminate\Routing\Route;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reflects over the action that a route runs.
 *
 * Discovery reads three things from the action: its `#[RequiresPayment]` and
 * `#[DiscoveryInfo]` attributes, its docblock, and the FormRequest that it
 * type-hints. The action of a route is a closure as often as it is
 * `Controller@method`. One class resolves both forms, so a closure route gets
 * as much documentation as a controller route.
 */
final class RouteAction
{
    /**
     * Returns the action as a reflection object.
     *
     * The method returns null when reflection cannot reach the action, for
     * example a missing class or a string callable that points to nothing. A
     * caller treats null as "the route states nothing".
     */
    public static function reflect(Route $route): ?ReflectionFunctionAbstract
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return new ReflectionFunction($uses);
        }

        if (! is_string($uses) || $uses === '') {
            return null;
        }

        [$class, $method] = str_contains($uses, '@')
            ? explode('@', $uses, 2)
            : [$uses, '__invoke'];

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        return new ReflectionMethod($class, $method);
    }

    /**
     * Reads an attribute from the action, and then from the class.
     *
     * A site owner can annotate a whole controller once, in the same way as
     * `#[RequiresPayment]` allows.
     *
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    public static function attribute(Route $route, string $attribute): ?object
    {
        $reflection = self::reflect($route);

        if ($reflection === null) {
            return null;
        }

        $attributes = $reflection->getAttributes($attribute);

        if ($attributes === [] && $reflection instanceof ReflectionMethod) {
            $attributes = $reflection->getDeclaringClass()->getAttributes($attribute);
        }

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Returns the built-in types that the action type-hints, by parameter name.
     *
     * A path parameter reaches the action as an argument, so `match(int $id)`
     * states that `{id}` is an integer. The package types the published
     * parameter from that, rather than from the string that every path segment
     * is on the wire.
     *
     * The method returns only a built-in type. A class type-hint is route-model
     * binding, and the class states nothing about the shape of the segment that
     * the client sends.
     *
     * @return array<string, string> parameter name => built-in type name
     */
    public static function scalarTypes(Route $route): array
    {
        $reflection = self::reflect($route);

        if ($reflection === null) {
            return [];
        }

        $types = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                $types[$parameter->getName()] = $type->getName();
            }
        }

        return $types;
    }
}
