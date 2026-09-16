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
 * MPP clients use discovery to find payable endpoints before making a
 * request; the document is advisory only — the runtime 402 challenge stays
 * authoritative. Because of that, nothing PRICED here is hand-maintained: the
 * generator walks the router for routes gated by the `mpp` middleware or the
 * #[RequiresPayment] attribute and derives each `x-payment-info` from the same
 * config and request builders that mint the live challenge, so the two can
 * never disagree on amount, currency or offered methods.
 *
 * Price precedence mirrors Payment\SpecResolver — route argument, then
 * price_book entry, then `mpp.defaults` — and the offered method set mirrors
 * its resolveOfferedMethods(); the two are read together. SpecResolver itself
 * needs a Request (for the route-derived scope, which discovery does not
 * advertise), so the precedence is replicated here off the same config keys.
 *
 * Routes with resolver-driven (dynamic) pricing advertise their offered
 * methods with an explicit `amount: null` — every x-payment-info field is
 * optional in the discovery schema, but the key is present so a client can
 * tell "priced later" from "not stated", and the 402 carries the real price.
 *
 * Everything the draft asks for that a price cannot supply — what the operation
 * does, what to send it, what comes back, who runs the service — is stated by
 * the site owner or read off the application; OperationInfoResolver and
 * ServiceInfo settle where each field comes from.
 */
class DiscoveryDocument
{
    /**
     * Stand-in amount used to derive a rail's currency identity for a route that
     * states no price. Each builder owns its currency (a token address on Tempo,
     * an ISO code on fiat) and none of it depends on the amount, so an unpriced
     * offer can still advertise the currency the live 402 will carry. The probe
     * itself never reaches the document.
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
            // Cheapest question first, and asked of every route in the
            // application: is this one listed at all? Describing a route means
            // reflecting over its action, instantiating its attributes and
            // autoloading every class it type-hints, and in an app of any size
            // almost every route is about to be discarded.
            $info = $this->paymentInfoFor($route);

            if ($info === null && ! $this->included($route, $included)) {
                continue;
            }

            $meta = $this->operations->for($route);

            if ($meta->hidden === true) {
                // Payable, deliberately unlisted. A site owner may want an
                // endpoint chargeable without advertising it to every crawler;
                // the 402 is unaffected.
                continue;
            }

            if ($info !== null) {
                $info = $this->annotate($info, $meta);
            }

            $variants = $this->pathVariants($route, $meta);

            // One route serving several paths (an optional parameter is really
            // two operations) cannot reuse one operationId, which OpenAPI
            // requires to be unique across the document.
            if (count($variants) > 1) {
                $meta = $meta->withOperationId(null);
            }

            // Both are fixed for the route, and `operation()` below is called
            // once per path variant per verb.
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
     * One operation object. A null `$info` is a free route that config asked to
     * be listed: documented like any other, but with no payment extension and
     * no 402, because it is not payable and saying otherwise would be a lie a
     * client acts on.
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
            // A stated body on a GET is unusual but legal, and a site owner who
            // wrote one meant it.
            $operation['requestBody'] = $body;
        } elseif ($info !== null && RouteKeys::carriesBody($httpMethod)) {
            // Discovery consumers expect a body-carrying PAYABLE operation to
            // declare a requestBody, so a permissive JSON object stands in when
            // the body is app-defined and undeclared. A free route gets no such
            // placeholder: `{"type": "object"}` says nothing, and the reason to
            // say it anyway does not apply.
            $operation['requestBody'] = ['content' => ['application/json' => ['schema' => ['type' => 'object']]]];
        }

        if ($responses === []) {
            // Only when the operation described no response at all. A route that
            // named its own — a 307 redirect, a 404 — does not also get a 200 it
            // never returns.
            $responses = ['200' => ['description' => 'Successful response']];
        }

        if ($info !== null) {
            // Required by the draft on every payable operation, whatever else
            // the site owner said.
            $responses += ['402' => ['description' => 'Payment Required']];
        }

        $operation['responses'] = $responses;

        return $operation;
    }

    /**
     * Whether a route that charges nothing should nonetheless be listed.
     *
     * The document describes an API surface, and a paid API usually has free
     * parts — a redirect, a status endpoint, the free tier of a paid one — that
     * an agent planning a call needs to know about and would otherwise have to
     * discover by paying for something. `mpp.discovery.include` names them,
     * matched against the same keys `operations` uses, with `*` wildcards.
     *
     * Empty by default, and deliberately opt-in per route: a broad pattern
     * publishes your route table to an unauthenticated endpoint that registries
     * crawl, which is a decision about disclosure rather than a convenience.
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
     * The OpenAPI paths one route serves, each with its own path parameters.
     *
     * A Laravel route is one pattern; an OpenAPI path is one template, and a
     * path parameter in it is always required. An optional Laravel parameter —
     * `/report/{year?}` — is therefore two OpenAPI paths rather than one
     * optional parameter, and emitting `{year?}` verbatim (as this generator
     * once did) publishes a template whose parameter is literally named
     * "year?" and is declared nowhere.
     *
     * @return array<string, list<array<string, mixed>>> path => parameter objects
     */
    private function pathVariants(Route $route, OperationInfo $meta): array
    {
        $uri = '/'.ltrim($route->uri(), '/');
        $wheres = $route->wheres;

        preg_match_all('/\{\s*(\w+)\s*(\?)?\s*\}/', $uri, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $variants = [];
        $parameters = [];

        foreach ($matches as $match) {
            $name = $match[1][0];
            $optional = ($match[2][0] ?? '') === '?';

            // Everything up to an optional parameter is a path in its own
            // right: the shorter form the route also answers.
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
            );
        }

        // The full form, with every optional parameter supplied, is always a
        // path too.
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

            $parameters[] = $this->parameter((string) $name, 'query', $required, $stated, null);
        }

        return $parameters;
    }

    /**
     * One OpenAPI parameter object. A site owner states either a description —
     * the only thing a path parameter usually needs — or a full parameter array
     * to merge, for the cases where it needs more.
     *
     * @param  string|array<string, mixed>|null  $stated
     * @return array<string, mixed>
     */
    private function parameter(string $name, string $in, bool $required, string|array|null $stated, ?string $pattern): array
    {
        $schema = ['type' => 'string'];

        if ($pattern !== null && $pattern !== '') {
            // A Laravel `where()` constraint is matched against the whole
            // segment; a JSON Schema `pattern` is a search unless anchored, so
            // an unanchored constraint is anchored on the way out to keep its
            // meaning.
            $schema['pattern'] = str_contains($pattern, '^') || str_contains($pattern, '$')
                ? $pattern
                : '^(?:'.$pattern.')$';
        }

        $parameter = ['name' => $name, 'in' => $in, 'required' => $required, 'schema' => $schema];

        if (is_string($stated) && $stated !== '') {
            $parameter['description'] = $stated;
        }

        if (is_array($stated)) {
            // The stated array wins field by field, so `['schema' => …]`
            // replaces the derived one and `['description' => …]` alone leaves
            // it in place.
            $parameter = $stated + $parameter;
        }

        return $parameter;
    }

    /**
     * Hand the finished document to the application's own post-processors, the
     * last word on everything.
     *
     * OpenAPI is large, this package models the part of it the payment drafts
     * care about, and the gap between the two is where a site owner would
     * otherwise be stuck: security schemes, webhooks, `$ref` components,
     * whatever OpenAPI adds next. Rather than grow a config key per field, the
     * document passes through `mpp.discovery.pipeline` — `[Class::class,
     * 'method']` pairs, resolved through the container so the registry survives
     * `config:cache` — each taking the document array and returning it.
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
                Log::warning("[mpp] Discovery pipeline stage {$stage[0]}::{$stage[1]}() did not return the document array; ignoring it.");

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
            // Not gated by the `mpp` middleware; the action may still carry the
            // attribute (the automatic-enforcer route style).
            $resolved = $this->attributeArgs($route);
        } else {
            // A bare `mpp` reads the action's attribute at runtime, so discovery
            // does too. A gated route that states nothing anywhere still
            // advertises whatever the global defaults price it at.
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
     * Fold the route's price notes into its offers.
     *
     * A note is documentation, so it is applied here rather than inside
     * `offer()`: pricing an offer and describing one are separate jobs, and the
     * description then lands the same way whether the rail priced cleanly or
     * failed to.
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
     * The raw arguments of the route's `mpp` middleware — `mpp:0.50,USD,…` gives
     * `['0.50', 'USD', …]`, a bare `mpp` gives `[]`.
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
     * Read a price out of middleware arguments exactly as SpecResolver does: a
     * leading price_book key names an entry, otherwise the leading positional
     * arguments are the amount and currency.
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

        // Positional args — amount, then currency — run until the first
        // key=value option, so `mpp:scope=clip` (no positional) inherits the
        // global price rather than reading "scope=clip" as an amount.
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
     * Fill what the route left unstated from `mpp.defaults`, and settle the
     * offered method set — SpecResolver's precedence, on the same config keys.
     *
     * @param  list<string>|null  $methods
     * @return array{?string, string, list<string>}
     */
    private function resolve(string|float|null $amount, ?string $currency, ?string $method, ?array $methods): array
    {
        $amount ??= config('mpp.defaults.amount');

        return [
            // "States no price" is null: such a route is priced by its
            // resolvers, at request time.
            ($amount === null || $amount === '') ? null : (string) $amount,
            strtoupper($currency ?: (string) (config('mpp.defaults.currency') ?: 'USD')),
            OfferedMethods::resolve($method, $methods),
        ];
    }

    /**
     * One discovery offer, derived through the same builder that mints the
     * live challenge. Amounts are minor-unit integer strings, per the
     * discovery schema; `amount` is null when only a request can price the
     * route.
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
            // A misconfigured rail must not take discovery down: the offer still
            // says the rail is on, without claiming a price. The live 402 will
            // surface the configuration error where it can be acted on — but
            // only once someone calls the route, so say it here too.
            Log::warning("[mpp] Discovery could not derive the '{$method}' offer: ".$e->getMessage());

            return $offer + ['amount' => null];
        }

        // The rail's own conversion is authoritative for a stated price; an
        // unstated one stays null however the probe converted.
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
     * A price_book entry's own offered set, accepting either an array or the
     * pipe-separated string the middleware option takes.
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
