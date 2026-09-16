<?php

namespace Square1\Mpp\Discovery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Square1\Mpp\Attributes\DiscoveryInfo;

/**
 * Settles what the discovery document says about one route.
 *
 * Four sources, in descending precedence:
 *
 *   1. the route — `->discovery(summary: '…')`;
 *   2. the action — `#[DiscoveryInfo(summary: '…')]`;
 *   3. `config('mpp.discovery.operations')`, keyed by route name or `"GET /uri"`;
 *   4. the application itself.
 *
 * The fourth is the point of the other three being optional. A Laravel route
 * already carries most of what a registry wants to know: it has a name, which is
 * an `operationId`; its action has a docblock, which is a summary; its action
 * type-hints a FormRequest, whose rules are an input schema. The package reads
 * those rather than asking for them a second time, on the same principle that
 * makes it derive prices from the live router instead of a hand-kept list —
 * a document maintained apart from the thing it describes goes stale, and a
 * stale one is worse than a thin one.
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
     * The per-operation config block for this route, looked up by route name
     * first and then by `"GET /uri"` and `"/uri"`. The name is the stable
     * identity and the preferred key; the URI forms are there for routes that
     * have no name, which is most closure routes.
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
     * What the application already states, read off the route and its action.
     * This is the lowest-precedence source: anything written for discovery
     * deliberately outranks anything inferred on its behalf.
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
            // Only for a verb that carries a body. A GET action may well
            // type-hint a FormRequest to validate its query string, and
            // publishing those rules as a request BODY would describe a request
            // no client should send.
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
     * A route's name is a stable, unique, application-chosen identifier for an
     * operation, which is exactly what `operationId` is for. A route serving
     * several verbs needs one per operation, so the verb is appended there —
     * `operationId` must be unique across the whole document.
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
     * The first FormRequest the action type-hints. Laravel injects it to
     * validate the body, so its rules describe the body — which is what the
     * draft's input schema is.
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
     * The action's docblock, split the way OpenAPI splits operation prose: the
     * first paragraph is the `summary`, the rest is the `description`. Annotation
     * tags are dropped — `@param` is for the reader of the code, not for an
     * agent deciding whether to pay.
     *
     * Off by default. A docblock is written for colleagues and can say things
     * its author would not publish unauthenticated to a registry, so turning it
     * into public API documentation has to be a decision rather than an upgrade.
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

        // Trim the blank lines the comment delimiters leave behind, then split
        // on the first blank line: summary above, description below.
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
