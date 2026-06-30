<?php

namespace Square1\Mpp\Payment;

use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use Square1\Mpp\Attributes\RequiresPayment;
use Throwable;

/**
 * Reads the #[RequiresPayment] attribute from a matched route's controller
 * action (method-level first, then class-level for invokable/whole controllers).
 * Returns null for closures or unattributed actions.
 */
class AttributeResolver
{
    public static function forRoute(?Route $route): ?RequiresPayment
    {
        if ($route === null) {
            return null;
        }

        try {
            $controller = $route->getController();
        } catch (Throwable $e) {
            return null;
        }

        if (! is_object($controller)) {
            return null;
        }

        $method = $route->getActionMethod();

        try {
            if (is_string($method) && $method !== '' && method_exists($controller, $method)) {
                $attrs = (new ReflectionMethod($controller, $method))->getAttributes(RequiresPayment::class);
                if ($attrs !== []) {
                    return $attrs[0]->newInstance();
                }
            }

            $classAttrs = (new ReflectionClass($controller))->getAttributes(RequiresPayment::class);

            return $classAttrs !== [] ? $classAttrs[0]->newInstance() : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
