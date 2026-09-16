<?php

namespace Square1\Mpp\Discovery;

use Closure;
use Illuminate\Routing\Route;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * Reflection over whatever a route was given to run.
 *
 * Discovery reads three things off the action — its `#[RequiresPayment]` and
 * `#[DiscoveryInfo]` attributes, its docblock, and its type-hinted FormRequest —
 * and a route's action is a closure as often as it is `Controller@method`. One
 * place resolves it so a closure route is not quietly worth less documentation
 * than a controller one.
 */
final class RouteAction
{
    /**
     * The action as something reflectable, or null when the route runs
     * something reflection cannot reach (a missing class, a string callable to
     * nowhere). Callers treat null as "the route states nothing".
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
     * Read an attribute off the action, falling back to the class — a whole
     * controller can be annotated once, exactly as `#[RequiresPayment]` allows.
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
}
