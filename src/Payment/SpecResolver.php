<?php

namespace Square1\Mpp\Payment;

use Illuminate\Http\Request;
use Square1\Mpp\Attributes\RequiresPayment;

/**
 * Builds a PaymentSpec from middleware arguments or from a #[RequiresPayment]
 * attribute.
 *
 * The class fills the defaults from the configuration: the method, the network
 * id, the payment method types, and a scope that it derives from the route.
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
                // A book entry can omit the amount too, when it carries resolvers.
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

        // The positional arguments are the amount and then the currency. They
        // end at the first key=value option. `mpp:scope=clip` has no positional
        // argument, so it can inherit the global price.
        $positional = [];
        foreach ($args as $arg) {
            if (str_contains($arg, '=')) {
                break;
            }
            $positional[] = $arg;
        }

        $options = $this->parseOptions(array_slice($args, count($positional)));

        // The amount can be null, because a route can leave the price to its
        // resolvers. The pipeline is the class that requires a price, after the
        // resolvers have run.
        $amount = $positional[0] ?? $this->defaultAmount();

        // This is a per-route override: `methods=stripe|other`, pipe-separated
        // and ordered.
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
        // Null is allowed here too. See fromMiddlewareArgs.
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
        // Normalise "stated no price" to null. Every later step tests for
        // null.
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
     * Parses a per-route pipe-separated option into an ordered list of names.
     *
     * The options are `methods=a|b`, `preconditions=a|b` and `pricing=a|b`. The
     * method returns an empty list when the option is unset or blank.
     *
     * @param  array<string, string>  $options
     * @return list<string>
     */
    private function parseNamedList(array $options, string $key): array
    {
        return $this->splitList($options[$key] ?? null);
    }

    /**
     * The one rule for a pipe-separated list: split it, trim each name, and drop
     * the blank names.
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
     * Reads the list of names in a price_book entry.
     *
     * The entry states either an array or the pipe-separated string that the
     * middleware option takes.
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
        // build() is the one place that normalises "stated no price" to null.
        // This method therefore returns what the config holds.
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
