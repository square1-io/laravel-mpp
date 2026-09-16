<?php

namespace Square1\Mpp\Discovery;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Square1\Mpp\Attributes\RequiresPayment;
use Square1\Mpp\Payment\OfferedMethods;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Protocol\ChallengeFactory;

/**
 * Builds the OpenAPI 3.1 discovery document served at /openapi.json.
 *
 * MPP clients read the document to find payable endpoints before they send a
 * request. The document is advisory. The runtime 402 challenge is
 * authoritative.
 *
 * No price in the document is hand-maintained. The generator reads the router
 * for routes that the `mpp` middleware or the #[RequiresPayment] attribute
 * gates. It derives each `x-payment-info` from the config and the request
 * builders that mint the live challenge. The document and the challenge
 * therefore cannot disagree on amount, currency or offered methods.
 *
 * Price precedence is the same as in Payment\SpecResolver: the route argument
 * first, then the price_book entry, then `mpp.defaults`. The offered method set
 * is the same as its resolveOfferedMethods(). Read the two classes together.
 * SpecResolver needs a Request to derive the scope, and discovery does not
 * advertise the scope. This class therefore repeats the precedence and reads
 * the same config keys.
 *
 * A route with resolver-driven (dynamic) pricing advertises its offered methods
 * with an explicit `amount: null`. Every x-payment-info field is optional in
 * the discovery schema. This key is present so that a client can tell "priced
 * later" from "not stated". The 402 carries the real price.
 *
 * The draft also asks for data that a price cannot supply: what the operation
 * does, what to send to it, what it returns, and who operates the service. The
 * site owner states this data, or the package reads it from the application.
 * OperationInfoResolver and ServiceInfo define where each field comes from.
 */
class DiscoveryDocument
{
    /**
     * A stand-in amount that lets the generator find the currency of a rail for
     * a route that states no price.
     *
     * Each builder owns its currency: a token address on Tempo, an ISO code on
     * fiat. The currency does not depend on the amount. An unpriced offer can
     * therefore advertise the currency that the live 402 will carry. This probe
     * amount never reaches the document.
     */
    private const PROBE_AMOUNT = '1';

    public function __construct(
        private readonly Router $router,
        private readonly OperationInfoResolver $operations = new OperationInfoResolver,
        private readonly SchemaResolver $schemas = new SchemaResolver,
        private readonly ServiceInfo $service = new ServiceInfo,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $paths = [];
        $included = (array) config('mpp.discovery.include', []);

        foreach ($this->router->getRoutes() as $route) {
            // Ask the cheapest question first, for every route in the
            // application: does the document list this route? To describe a
            // route, the package reflects over its action, builds its
            // attributes, and autoloads every class that the action type-hints.
            // In an application of any size, the generator discards almost
            // every route.
            $info = $this->paymentInfoFor($route);

            if ($info === null && ! $this->included($route, $included)) {
                continue;
            }

            $meta = $this->operations->for($route);

            if ($meta->hidden === true) {
                // The route is payable but deliberately unlisted. A site owner
                // can charge for an endpoint and not advertise it to every
                // crawler. The 402 does not change.
                continue;
            }

            if ($info !== null) {
                $info = $this->annotate($info, $meta);
            }

            $variants = $this->pathVariants($route, $meta);

            // One route can serve several paths, because an optional parameter
            // is two operations. Those operations cannot share one operationId.
            // OpenAPI requires the operationId to be unique in the document.
            if (count($variants) > 1) {
                $meta = $meta->withOperationId(null);
            }

            // The body and the responses are the same for every operation of
            // this route. `operation()` below runs once for each path variant
            // and each verb.
            $body = $this->schemas->requestBody($meta->request);
            $responses = $this->schemas->responses($meta->response);

            foreach ($variants as $path => $parameters) {
                foreach (RouteKeys::verbs($route) as $httpMethod) {
                    $paths[$path][strtolower($httpMethod)] = $this->operation(
                        $info,
                        $meta,
                        $parameters,
                        $httpMethod,
                        $body,
                        $responses,
                    );
                }
            }
        }

        $document = ['openapi' => '3.1.0', 'info' => $this->service->info()];

        if (($servers = $this->service->servers()) !== []) {
            $document['servers'] = $servers;
        }

        if (($serviceInfo = $this->service->serviceInfo()) !== null) {
            $document['x-service-info'] = $serviceInfo;
        }

        $document['paths'] = $paths === [] ? (object) [] : $paths;

        return $this->pipeline($document);
    }

    /**
     * Builds one operation object.
     *
     * A null `$info` is a free route that the config asked the package to list.
     * The package documents it in the same way as any other route. It adds no
     * payment extension and no 402, because the route is not payable and a
     * client would act on a 402 that it will never receive.
     *
     * @param  array{offers: non-empty-list<array<string, mixed>>}|null  $info
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>|null  $body
     * @param  array<string, array<string, mixed>>  $responses
     * @return array<string, mixed>
     */
    private function operation(?array $info, OperationInfo $meta, array $parameters, string $httpMethod, ?array $body, array $responses): array
    {
        $operation = array_filter([
            'operationId' => $meta->operationId,
            'summary' => $meta->summary,
            'description' => $meta->description,
            'tags' => $meta->tags,
        ], fn (mixed $value) => $value !== null && $value !== []);

        if ($meta->deprecated === true) {
            $operation['deprecated'] = true;
        }

        if ($info !== null) {
            $operation['x-payment-info'] = $info;
        }

        $parameters = [...$parameters, ...$this->queryParameters($meta)];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== null) {
            // A stated body on a GET request is unusual but legal. A site
            // owner who writes one intends it.
            $operation['requestBody'] = $body;
        } elseif ($info !== null && RouteKeys::carriesBody($httpMethod)) {
            // Discovery clients expect a payable operation that carries a body
            // to declare a requestBody. A permissive JSON object stands in when
            // the application defines the body but does not declare it. A free
            // route gets no placeholder. `{"type": "object"}` states nothing,
            // and the reason to state it does not apply to a free route.
            $operation['requestBody'] = ['content' => ['application/json' => ['schema' => ['type' => 'object']]]];
        }

        if ($responses === []) {
            // This applies only when the operation described no response at
            // all. A route that named its own responses, such as a 307 redirect
            // and a 404, does not also get a 200 that it never returns.
            $responses = ['200' => ['description' => 'Successful response']];
        }

        if ($info !== null) {
            // The draft requires a 402 on every payable operation, whatever
            // else the site owner stated.
            $responses += ['402' => ['description' => 'Payment Required']];
        }

        $operation['responses'] = $responses;

        return $operation;
    }

    /**
     * Reports whether the document lists a route that charges nothing.
     *
     * The document describes an API surface, and a paid API usually has free
     * parts: a redirect, a status endpoint, or the free tier of a paid
     * endpoint. An agent that plans a call needs to know about them. Without
     * this list, the agent must pay for a request to find them.
     *
     * `mpp.discovery.include` names the free routes. The package matches the
     * patterns against the same keys as `operations`, and accepts `*`
     * wildcards.
     *
     * The list is empty by default, and each route must opt in. A broad pattern
     * publishes your route table on an unauthenticated endpoint that registries
     * crawl. That is a decision about disclosure, not a convenience.
     *
     * @param  list<string>  $patterns
     */
    private function included(Route $route, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }

        foreach (RouteKeys::for($route) as $key) {
            if (Str::is($patterns, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the OpenAPI paths that one route serves, each with its own path
     * parameters.
     *
     * A Laravel route is one pattern. An OpenAPI path is one template, and a
     * path parameter in a template is always required. An optional Laravel
     * parameter such as `/report/{year?}` is therefore two OpenAPI paths, not
     * one optional parameter. An earlier version of this generator emitted
     * `{year?}` without a change. That output declares a parameter named
     * "year?" nowhere, and it is not valid OpenAPI.
     *
     * @return array<string, list<array<string, mixed>>> path => parameter objects
     */
    private function pathVariants(Route $route, OperationInfo $meta): array
    {
        $uri = '/'.ltrim($route->uri(), '/');
        $wheres = $route->wheres;
        $types = RouteAction::scalarTypes($route);

        preg_match_all('/\{\s*(\w+)\s*(\?)?\s*\}/', $uri, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $variants = [];
        $parameters = [];

        foreach ($matches as $match) {
            $name = $match[1][0];
            $optional = ($match[2][0] ?? '') === '?';

            // The part of the URI before an optional parameter is also a
            // path. It is the shorter form that the route answers.
            if ($optional) {
                $shorter = rtrim(substr($uri, 0, $match[0][1]), '/');
                $variants[$shorter === '' ? '/' : $shorter] = $parameters;
            }

            $parameters[] = $this->parameter(
                $name,
                'path',
                true,
                $meta->parameters[$name] ?? null,
                is_string($wheres[$name] ?? null) ? $wheres[$name] : null,
                $types[$name] ?? null,
            );
        }

        // The full form, which supplies every optional parameter, is always a
        // path.
        $variants[preg_replace('/\{\s*(\w+)\s*\?\s*\}/', '{$1}', $uri) ?? $uri] = $parameters;

        return $variants;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryParameters(OperationInfo $meta): array
    {
        $parameters = [];

        foreach ($meta->query as $name => $stated) {
            $required = is_array($stated) && ($stated['required'] ?? false) === true;

            $parameters[] = $this->parameter((string) $name, 'query', $required, $stated, null, null);
        }

        return $parameters;
    }

    /**
     * Builds one OpenAPI parameter object.
     *
     * A site owner states a description, which is usually all that a path
     * parameter needs. A site owner can also state a full parameter array,
     * which the package merges, for a parameter that needs more.
     *
     * @param  string|array<string, mixed>|null  $stated
     * @return array<string, mixed>
     */
    private function parameter(string $name, string $in, bool $required, string|array|null $stated, ?string $pattern, ?string $type): array
    {
        $schema = ['type' => $this->parameterType($pattern, $type)];

        // A `pattern` constrains a string. It means nothing on a number, and
        // the type already carries what the constraint stated.
        if ($schema['type'] === 'string' && $pattern !== null && $pattern !== '') {
            // Laravel matches a `where()` constraint against the whole
            // segment. A JSON Schema `pattern` is a search unless it is
            // anchored. The package therefore anchors a constraint that is not
            // anchored, to keep its meaning.
            $schema['pattern'] = str_contains($pattern, '^') || str_contains($pattern, '$')
                ? $pattern
                : '^(?:'.$pattern.')$';
        }

        $parameter = ['name' => $name, 'in' => $in, 'required' => $required, 'schema' => $schema];

        if (is_string($stated) && $stated !== '') {
            $parameter['description'] = $stated;
        }

        if (is_array($stated)) {
            // The stated array wins field by field. `['schema' => …]` replaces
            // the derived schema. `['description' => …]` alone keeps it.
            $parameter = $stated + $parameter;
        }

        return $parameter;
    }

    /**
     * Returns the JSON Schema type for a parameter.
     *
     * Every path segment is a string on the wire, and OpenAPI still allows a
     * parameter to declare a primitive type. Two signals state one, and either
     * is enough:
     *
     *   - the action type-hints the argument, as in `show(int $id)`. Laravel
     *     casts the segment to that type before the action runs.
     *   - a `where()` constraint matches digits and nothing else, which is what
     *     `whereNumber()` assigns.
     *
     * The type-hint decides first, because it is unambiguous. A constraint
     * decides only when it is open-ended, such as `[0-9]+`. A bounded
     * constraint such as `[0-9]{4}` states a length as well as a type, and a
     * `pattern` on a string keeps both.
     */
    private function parameterType(?string $pattern, ?string $type): string
    {
        $fromHint = match ($type) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            default => null,
        };

        if ($fromHint !== null) {
            return $fromHint;
        }

        if ($pattern !== null && preg_match('/^(\[0-9\]|\\d)[+*]$/', $pattern) === 1) {
            return 'integer';
        }

        return 'string';
    }

    /**
     * Passes the finished document to the post-processors of the application.
     *
     * OpenAPI is large. This package models the part of it that the payment
     * drafts use. A site owner can need a part that the package does not model,
     * such as a security scheme, a webhook, a `$ref` component, or a field that
     * OpenAPI adds later. The package does not add a config key for each field.
     * The document passes through `mpp.discovery.pipeline` instead. Each entry
     * is a `[Class::class, 'method']` pair that the container resolves, so the
     * list survives `config:cache`. Each stage receives the document array and
     * returns it.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function pipeline(array $document): array
    {
        foreach ((array) config('mpp.discovery.pipeline', []) as $stage) {
            if (! is_array($stage) || count($stage) !== 2 || ! is_string($stage[0])) {
                Log::warning('[mpp] Ignoring a mpp.discovery.pipeline entry that is not a [Class::class, \'method\'] pair.');

                continue;
            }

            try {
                $result = app($stage[0])->{$stage[1]}($document);
            } catch (\Throwable $e) {
                Log::warning("[mpp] Discovery pipeline stage {$stage[0]}::{$stage[1]}() failed: ".$e->getMessage());

                continue;
            }

            if (! is_array($result)) {
                Log::warning("[mpp] Discovery pipeline stage {$stage[0]}::{$stage[1]}() did not return the document array. The package ignores this stage.");

                continue;
            }

            $document = $result;
        }

        return $document;
    }

    /**
     * @return array{offers: non-empty-list<array<string, mixed>>}|null null when the route is not payment-gated
     */
    private function paymentInfoFor(Route $route): ?array
    {
        $args = $this->middlewareArgs($route);

        if ($args === null) {
            // The `mpp` middleware does not gate this route. The action can
            // still carry the attribute, which is the automatic-enforcer route
            // style.
            $resolved = $this->attributeArgs($route);
        } else {
            // A bare `mpp` reads the attribute of the action at runtime, so
            // discovery reads it too. A gated route that states nothing
            // anywhere still advertises the price that the global defaults
            // give it.
            $resolved = ($args === [] ? $this->attributeArgs($route) : $this->fromMiddlewareArgs($args))
                ?? $this->resolve(null, null, null, null);
        }

        if ($resolved === null) {
            return null;
        }

        [$amount, $currency, $methods] = $resolved;

        $offers = [];
        foreach ($methods as $method) {
            $offers[] = $this->offer($method, $amount, $currency);
        }

        return $offers === [] ? null : ['offers' => $offers];
    }

    /**
     * Adds the price notes of the route to its offers.
     *
     * A note is documentation, so the package applies it here and not in
     * `offer()`. To price an offer and to describe an offer are separate jobs.
     * The description then arrives in the same way whether the rail priced the
     * offer or failed to price it.
     *
     * @param  array{offers: non-empty-list<array<string, mixed>>}  $info
     * @return array{offers: non-empty-list<array<string, mixed>>}
     */
    private function annotate(array $info, OperationInfo $meta): array
    {
        if ($meta->priceNote === null) {
            return $info;
        }

        foreach ($info['offers'] as $i => $offer) {
            $note = $meta->priceNoteFor((string) $offer['method']);

            if ($note !== null) {
                $info['offers'][$i]['description'] = $note;
            }
        }

        return $info;
    }

    /**
     * Returns the raw arguments of the `mpp` middleware of the route.
     *
     * `mpp:0.50,USD,…` gives `['0.50', 'USD', …]`. A bare `mpp` gives `[]`.
     *
     * @return list<string>|null null when the route carries no `mpp` middleware
     */
    private function middlewareArgs(Route $route): ?array
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! preg_match('/^mpp(?::(.*))?$/', $middleware, $m)) {
                continue;
            }

            return array_values(array_filter(
                array_map('trim', explode(',', $m[1] ?? '')),
                fn (string $arg) => $arg !== '',
            ));
        }

        return null;
    }

    /**
     * Reads a price from the middleware arguments, in the same way as
     * SpecResolver.
     *
     * A leading price_book key names an entry. If there is no such key, the
     * leading positional arguments are the amount and the currency.
     *
     * @param  non-empty-list<string>  $args
     * @return array{?string, string, list<string>}
     */
    private function fromMiddlewareArgs(array $args): array
    {
        $priceBook = (array) config('mpp.price_book', []);

        if (! str_contains($args[0], '=') && isset($priceBook[$args[0]])) {
            $entry = (array) $priceBook[$args[0]];
            $options = $this->options(array_slice($args, 1));

            return $this->resolve(
                amount: $entry['amount'] ?? null,
                currency: $entry['currency'] ?? null,
                method: $options['method'] ?? null,
                methods: $this->splitList($options['methods'] ?? null)
                    ?: $this->entryMethods($entry),
            );
        }

        // The positional arguments are the amount and then the currency. They
        // end at the first key=value option. `mpp:scope=clip` has no positional
        // argument, so it inherits the global price. The package does not read
        // "scope=clip" as an amount.
        $positional = [];
        foreach ($args as $arg) {
            if (str_contains($arg, '=')) {
                break;
            }
            $positional[] = $arg;
        }

        $options = $this->options(array_slice($args, count($positional)));

        return $this->resolve(
            amount: $positional[0] ?? null,
            currency: $positional[1] ?? null,
            method: $options['method'] ?? null,
            methods: $this->splitList($options['methods'] ?? null),
        );
    }

    /**
     * @return array{?string, string, list<string>}|null
     */
    private function attributeArgs(Route $route): ?array
    {
        $attr = RouteAction::attribute($route, RequiresPayment::class);

        if (! $attr instanceof RequiresPayment) {
            return null;
        }

        return $this->resolve(
            amount: $attr->amount,
            currency: $attr->currency,
            method: $attr->method,
            methods: $attr->methods,
        );
    }

    /**
     * Fills what the route left unstated from `mpp.defaults`, and settles the
     * offered method set.
     *
     * The precedence is the precedence of SpecResolver, on the same config
     * keys.
     *
     * @param  list<string>|null  $methods
     * @return array{?string, string, list<string>}
     */
    private function resolve(string|float|null $amount, ?string $currency, ?string $method, ?array $methods): array
    {
        $amount ??= config('mpp.defaults.amount');

        return [
            // Null means that the route states no price. The resolvers of the
            // route price it at request time.
            ($amount === null || $amount === '') ? null : (string) $amount,
            strtoupper($currency ?: (string) (config('mpp.defaults.currency') ?: 'USD')),
            OfferedMethods::resolve($method, $methods),
        ];
    }

    /**
     * Builds one discovery offer through the builder that mints the live
     * challenge.
     *
     * An amount is a minor-unit integer string, as the discovery schema
     * requires. `amount` is null when only a request can price the route.
     *
     * @return array<string, mixed>
     */
    private function offer(string $method, ?string $amount, string $currency): array
    {
        $offer = ['method' => $method, 'intent' => 'charge'];
        $config = (array) config("mpp.methods.{$method}", []);

        $spec = new PaymentSpec(
            amount: $amount ?? self::PROBE_AMOUNT,
            currency: $currency,
            grants: 1,
            scope: 'discovery',
            method: $method,
        );

        try {
            $request = ChallengeFactory::builderFor($method, $config)->build($spec, $config);
        } catch (\Throwable $e) {
            // A misconfigured rail must not stop discovery. The offer still
            // states that the rail is available, and claims no price. The live
            // 402 reports the configuration error where someone can act on it,
            // but only after a client calls the route. The package therefore
            // also reports the error here.
            Log::warning("[mpp] Discovery could not derive the '{$method}' offer: ".$e->getMessage());

            return $offer + ['amount' => null];
        }

        // The conversion of the rail is authoritative for a stated price. An
        // unstated price stays null, whatever the probe converted.
        $offer['amount'] = $amount === null ? null : (string) $request['amount'];
        $offer['currency'] = (string) $request['currency'];

        return $offer;
    }

    /**
     * @param  list<string>  $args
     * @return array<string, string>
     */
    private function options(array $args): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', $arg, 2);
                $options[trim($key)] = trim($value);
            }
        }

        return $options;
    }

    /**
     * Returns the offered set of a price_book entry.
     *
     * The entry states either an array or the pipe-separated string that the
     * middleware option takes.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function entryMethods(array $entry): array
    {
        $methods = $entry['methods'] ?? null;

        if (is_string($methods) || $methods === null) {
            return $this->splitList($methods);
        }

        return array_values(array_map('strval', (array) $methods));
    }

    /**
     * @return list<string>
     */
    private function splitList(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $value))));
    }
}
