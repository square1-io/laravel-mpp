<?php

namespace Square1\Mpp\Discovery;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Square1\Mpp\Attributes\RequiresPayment;
use Square1\Mpp\Payment\OfferedMethods;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Protocol\ChallengeFactory;

/**
 * Builds the OpenAPI 3.1 discovery document served at /openapi.json.
 *
 * MPP clients use discovery to find payable endpoints before making a
 * request; the document is advisory only — the runtime 402 challenge stays
 * authoritative. Because of that, nothing here is hand-maintained: the
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

    public function __construct(private readonly Router $router) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $paths = [];

        foreach ($this->router->getRoutes() as $route) {
            $info = $this->paymentInfoFor($route);

            if ($info === null) {
                continue;
            }

            $path = '/'.ltrim($route->uri(), '/');

            foreach ($route->methods() as $httpMethod) {
                if (in_array($httpMethod, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $operation = [
                    'x-payment-info' => $info,
                    'responses' => [
                        '402' => ['description' => 'Payment Required'],
                        '200' => ['description' => 'Successful response'],
                    ],
                ];

                // Discovery consumers expect body-carrying operations to
                // declare a requestBody; a permissive JSON object is honest
                // for a payment-gated endpoint whose body is app-defined.
                if (in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true)) {
                    $operation['requestBody'] = [
                        'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                    ];
                }

                $paths[$path][strtolower($httpMethod)] = $operation;
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => config('mpp.discovery.title') ?? config('app.name', 'API'),
                'version' => (string) config('mpp.discovery.version', '1.0.0'),
            ],
            'paths' => $paths === [] ? (object) [] : $paths,
        ];
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
        $action = $route->getActionName();

        if (! str_contains($action, '@') && ! class_exists($action)) {
            return null;
        }

        [$class, $methodName] = str_contains($action, '@')
            ? explode('@', $action, 2)
            : [$action, '__invoke'];

        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod($methodName)) {
            return null;
        }

        $attributes = $reflection->getMethod($methodName)->getAttributes(RequiresPayment::class)
            ?: $reflection->getAttributes(RequiresPayment::class);

        if ($attributes === []) {
            return null;
        }

        $attr = $attributes[0]->newInstance();

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
