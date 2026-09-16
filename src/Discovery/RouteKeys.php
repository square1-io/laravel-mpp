<?php

namespace Square1\Mpp\Discovery;

use Illuminate\Routing\Route;

/**
 * The names config may refer to a route by.
 *
 * Two config keys name routes — `mpp.discovery.operations` and
 * `mpp.discovery.include` — and they have to agree on what a route is called,
 * or documenting a route and listing it would need two different spellings.
 *
 * A route answers to its name first, because a name is the stable identity: it
 * survives a URL change, which is exactly when a hand-written key would rot
 * silently. Failing that it answers to `"GET /uri"`, and then to its path for
 * the routes — most closure routes — that have no name at all.
 *
 * The path is offered with and without its leading slash. Laravel's own
 * `Route::uri()` has none and everything a developer reads has one, so both
 * spellings are in circulation and neither is wrong enough to reject.
 */
final class RouteKeys
{
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

        foreach ($route->methods() as $verb) {
            if (! in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                $keys[] = strtoupper($verb).' '.$uri;
            }
        }

        $keys[] = $uri;

        if (($bare = ltrim($uri, '/')) !== '') {
            $keys[] = $bare;
        }

        return $keys;
    }
}
