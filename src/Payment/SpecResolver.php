<?php

namespace Square1\Mpp\Payment;

use Illuminate\Http\Request;
use Square1\Mpp\Attributes\RequiresPayment;

/**
 * Builds a PaymentSpec from either middleware arguments or a #[RequiresPayment]
 * attribute, filling defaults (method, network id, payment method types, and a
 * route-derived scope) from configuration.
 */
class SpecResolver
{
    /**
     * @param  list<string>  $args  e.g. ['0.50','USD','grants=10','scope=report.basic'] or ['report.basic']
     */
    public function fromMiddlewareArgs(array $args, Request $request): PaymentSpec
    {
        $priceBook = config('mpp.price_book', []);

        if (isset($args[0]) && ! str_contains($args[0], '=') && isset($priceBook[$args[0]])) {
            $entry = $priceBook[$args[0]];
            $options = $this->parseOptions(array_slice($args, 1));

            return $this->build(
                // A book entry may omit the amount too, if it carries resolvers.
                $entry['amount'] ?? $this->defaultAmount(),
                strtoupper($entry['currency'] ?? $this->defaultCurrency()),
                (int) ($options['grants'] ?? $entry['grants'] ?? $this->defaultGrants()),
                $options['scope'] ?? $args[0],
                $options['method'] ?? null,
                $request,
                $this->parseNamedList($options, 'methods')
                    ?: ($this->entryList($entry, 'methods') ?: null),
                $this->parseNamedList($options, 'preconditions')
                    ?: $this->entryList($entry, 'preconditions'),
                $this->parseNamedList($options, 'pricing')
                    ?: $this->entryList($entry, 'pricing'),
            );
        }

        // Positional args — amount, then currency — run until the first key=value
        // option, so `mpp:scope=clip` (no positional) can inherit the global price.
        $positional = [];
        foreach ($args as $arg) {
            if (str_contains($arg, '=')) {
                break;
            }
            $positional[] = $arg;
        }

        $options = $this->parseOptions(array_slice($args, count($positional)));

        // May be null: a route can leave pricing entirely to its resolvers. The
        // pipeline is what insists a price exists, once they have had their say.
        $amount = $positional[0] ?? $this->defaultAmount();

        // A per-route override: `methods=stripe|other` (pipe-separated, ordered).
        $methods = $this->parseNamedList($options, 'methods') ?: null;

        return $this->build(
            $amount,
            strtoupper($positional[1] ?? $this->defaultCurrency()),
            (int) ($options['grants'] ?? $this->defaultGrants()),
            $options['scope'] ?? null,
            $options['method'] ?? null,
            $request,
            $methods,
            $this->parseNamedList($options, 'preconditions'),
            $this->parseNamedList($options, 'pricing'),
        );
    }

    public function fromAttribute(RequiresPayment $attribute, Request $request): PaymentSpec
    {
        // Null is allowed here too — see fromMiddlewareArgs.
        $amount = $attribute->amount ?? $this->defaultAmount();

        return $this->build(
            $amount,
            strtoupper($attribute->currency ?? $this->defaultCurrency()),
            $attribute->grants ?? $this->defaultGrants(),
            $attribute->scope,
            $attribute->method,
            $request,
            $attribute->methods,
            $attribute->preconditions,
            $attribute->pricing,
        );
    }

    /**
     * @param  list<string>|null  $methods  explicit per-route ordered method set, or null to use config defaults
     * @param  list<string>  $preconditions  named precondition checks to run for this route (in order)
     * @param  list<string>  $pricing  named price resolvers to apply for this route (in order)
     */
    private function build(string|float|null $amount, string $currency, int $grants, ?string $scope, ?string $method, Request $request, ?array $methods = null, array $preconditions = [], array $pricing = []): PaymentSpec
    {
        // Normalise "stated no price" to null; everything downstream tests for it.
        $amount = ($amount === null || $amount === '') ? null : (string) $amount;

        $offered = OfferedMethods::resolve($method, $methods);
        $primary = $offered[0];

        return new PaymentSpec(
            amount: $amount,
            currency: $currency,
            grants: max(1, $grants),
            scope: $scope ?: $this->defaultScope($request),
            method: $primary,
            offeredMethods: $offered,
            preconditions: $preconditions,
            pricing: $pricing,
        );
    }

    /**
     * Parse a per-route pipe-separated option — `methods=a|b`, `preconditions=a|b`,
     * `pricing=a|b` — into an ordered list of names. Empty when unset or blank.
     *
     * @param  array<string, string>  $options
     * @return list<string>
     */
    private function parseNamedList(array $options, string $key): array
    {
        return $this->splitList($options[$key] ?? null);
    }

    /**
     * The one pipe-separated-list rule: split, trim, drop the blanks.
     *
     * @return list<string>
     */
    private function splitList(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $value))));
    }

    /**
     * Read a price_book entry's own list of names, accepting either an array or
     * the same pipe-separated string the middleware option takes.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function entryList(array $entry, string $key): array
    {
        $value = $entry[$key] ?? null;

        if (is_string($value) || $value === null) {
            return $this->splitList($value);
        }

        return array_values(array_map('strval', (array) $value));
    }

    /**
     * @param  list<string>  $args
     * @return array<string, string>
     */
    private function parseOptions(array $args): array
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

    private function defaultAmount(): ?string
    {
        // build() is the single place that normalises "stated no price" to null,
        // so this only has to hand back what config holds.
        return config('mpp.defaults.amount');
    }

    private function defaultCurrency(): string
    {
        return (string) (config('mpp.defaults.currency') ?: 'USD');
    }

    private function defaultGrants(): int
    {
        return (int) (config('mpp.defaults.grants') ?? 1);
    }

    private function defaultScope(Request $request): string
    {
        $uri = $request->route()?->uri() ?? trim($request->path(), '/');
        $uri = preg_replace('/\{[^}]+\}/', '', (string) $uri);

        return trim(str_replace('/', '.', trim((string) $uri, '/')), '.') ?: 'default';
    }
}
