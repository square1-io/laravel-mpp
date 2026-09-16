<?php

namespace Square1\Mpp\Discovery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Square1\Mpp\Attributes\DiscoveryInfo;

/**
 * Settles what the discovery document states about one route.
 *
 * There are four sources, in descending precedence:
 *
 *   1. the route, as `->discovery(summary: '…')`;
 *   2. the action, as `#[DiscoveryInfo(summary: '…')]`;
 *   3. `config('mpp.discovery.operations')`, keyed by route name or `"GET /uri"`;
 *   4. the application itself.
 *
 * The fourth source is why the other three are optional. A Laravel route
 * already holds most of the data that a registry wants. The route has a name,
 * which is an `operationId`. The action has a docblock, which is a summary. The
 * action type-hints a FormRequest, and the rules of that class are an input
 * schema.
 *
 * The package reads that data instead of asking for it a second time. It
 * derives prices from the live router for the same reason. Documentation that
 * you keep apart from the code becomes incorrect, and incorrect documentation
 * is worse than short documentation.
 */
final class OperationInfoResolver
{
    public function for(Route $route): OperationInfo
    {
        $attribute = RouteAction::attribute($route, DiscoveryInfo::class);

        $stated = OperationInfo::fromArray((array) ($route->getAction('mpp_discovery') ?? []))
            ->mergeUnder($attribute === null
                ? OperationInfo::empty()
                : OperationInfo::fromAttribute($attribute))
            ->mergeUnder($this->fromConfig($route));

        return $stated->mergeUnder($this->derived($route));
    }

    /**
     * Returns the config block for this route.
     *
     * The method looks up the route name first, and then `"GET /uri"` and
     * `"/uri"`. The name is the stable identity and the preferred key. The URI
     * forms serve a route that has no name, which is most closure routes.
     */
    private function fromConfig(Route $route): OperationInfo
    {
        $operations = (array) config('mpp.discovery.operations', []);

        if ($operations === []) {
            return OperationInfo::empty();
        }

        foreach (RouteKeys::for($route) as $key) {
            if (is_array($operations[$key] ?? null)) {
                return OperationInfo::fromArray($operations[$key]);
            }
        }

        return OperationInfo::empty();
    }

    /**
     * Returns the data that the application already states, read from the route
     * and its action.
     *
     * This is the source with the lowest precedence. Anything that a site owner
     * writes for discovery outranks anything that the package infers.
     */
    private function derived(Route $route): OperationInfo
    {
        $reflection = RouteAction::reflect($route);

        [$summary, $description] = config('mpp.discovery.docblocks', false)
            ? $this->fromDocBlock($reflection)
            : [null, null];

        return new OperationInfo(
            summary: $summary,
            description: $description,
            operationId: $this->operationId($route),
            // This applies only to a verb that carries a body. A GET action
            // can type-hint a FormRequest to validate its query string. To
            // publish those rules as a request BODY would describe a request
            // that no client is to send.
            request: config('mpp.discovery.form_requests', true) && $this->carriesBody($route)
                ? $this->formRequest($reflection)
                : null,
        );
    }

    private function carriesBody(Route $route): bool
    {
        return array_filter(RouteKeys::verbs($route), RouteKeys::carriesBody(...)) !== [];
    }

    /**
     * Returns the `operationId` for a route.
     *
     * The name of a route is a stable and unique identifier that the
     * application chose, which is what `operationId` is for. A route that
     * serves several verbs is several operations, and `operationId` must be
     * unique in the whole document. Such a route therefore gets no id.
     */
    private function operationId(Route $route): ?string
    {
        $name = $route->getName();

        if ($name === null || $name === '') {
            return null;
        }

        return count(RouteKeys::verbs($route)) > 1 ? null : $name;
    }

    /**
     * Returns the first FormRequest that the action type-hints.
     *
     * Laravel injects the class to validate the body, so its rules describe the
     * body. The input schema of the draft is the same description.
     *
     * @return class-string|null
     */
    private function formRequest(?ReflectionFunctionAbstract $reflection): ?string
    {
        if ($reflection === null || ! class_exists(FormRequest::class)) {
            return null;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Returns the docblock of the action, split as OpenAPI splits operation
     * text.
     *
     * The first paragraph is the `summary`. The rest is the `description`. The
     * package drops the annotation tags. `@param` serves the reader of the
     * code, not an agent that decides whether to pay.
     *
     * This is off by default. An author writes a docblock for colleagues, and
     * it can contain text that the author would not publish to a registry on an
     * unauthenticated endpoint. To publish it must therefore be a decision, and
     * not a result of an upgrade.
     *
     * @return array{?string, ?string}
     */
    private function fromDocBlock(?ReflectionFunctionAbstract $reflection): array
    {
        $doc = $reflection?->getDocComment();

        if (! is_string($doc) || $doc === '') {
            return [null, null];
        }

        $lines = [];

        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim(preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? '');

            if (str_starts_with($line, '@')) {
                break;
            }

            $lines[] = $line;
        }

        // Remove the blank lines that the comment delimiters leave. Then split
        // at the first blank line. The summary is above it, and the description
        // is below it.
        while ($lines !== [] && $lines[0] === '') {
            array_shift($lines);
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        if ($lines === []) {
            return [null, null];
        }

        $break = array_search('', $lines, true);

        if ($break === false) {
            return [implode(' ', $lines), null];
        }

        $summary = implode(' ', array_slice($lines, 0, $break));
        $description = trim(implode("\n", array_slice($lines, $break + 1)));

        return [$summary ?: null, $description ?: null];
    }
}
