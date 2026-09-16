<?php

namespace Square1\Mpp\Discovery;

use Illuminate\Routing\Route;

/**
 * The names that the config can use for a route.
 *
 * Two config keys name routes: `mpp.discovery.operations` and
 * `mpp.discovery.include`. They must agree on the name of a route. If they did
 * not agree, a site owner would need two spellings, one to document a route and
 * one to list it.
 *
 * A route answers to its name first. A name is the stable identity, because it
 * survives a change of URL. A hand-written key fails silently at exactly that
 * point. A route then answers to `"GET /uri"`, and then to its path. Most
 * closure routes have no name, and the path is all that they have.
 *
 * The class returns the path with and without the leading slash. Laravel's own
 * `Route::uri()` has no leading slash, and the documentation a developer reads
 * has one. Both spellings are therefore in use, and neither one is wrong.
 */
final class RouteKeys
{
    /**
     * Returns the verbs that the document describes for a route.
     *
     * `HEAD` and `OPTIONS` belong to Laravel and to the HTTP stack, not to the
     * application. Three places exclude them, and those places must agree: the
     * operations that the document emits, the `"VERB /uri"` config keys that
     * can match an operation, and the test of whether a route name is unique
     * enough to be an `operationId`. If they disagree, a config key stops
     * matching without a message, or the document contains a duplicate
     * `operationId`, which OpenAPI forbids.
     *
     * @return list<string>
     */
    public static function verbs(Route $route): array
    {
        return array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
    }

    /**
     * Reports whether a verb carries a request body.
     *
     * This is the one question behind two decisions: whether the FormRequest
     * rules of an action describe the body, and whether an operation gets the
     * permissive placeholder body.
     */
    public static function carriesBody(string $verb): bool
    {
        return in_array(strtoupper($verb), ['POST', 'PUT', 'PATCH'], true);
    }

    /**
     * @return list<string> most specific first
     */
    public static function for(Route $route): array
    {
        $uri = '/'.ltrim($route->uri(), '/');
        $keys = [];

        if (($name = $route->getName()) !== null && $name !== '') {
            $keys[] = $name;
        }

        foreach (self::verbs($route) as $verb) {
            $keys[] = strtoupper($verb).' '.$uri;
        }

        $keys[] = $uri;

        if (($bare = ltrim($uri, '/')) !== '') {
            $keys[] = $bare;
        }

        return $keys;
    }
}
