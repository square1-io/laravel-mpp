<?php

namespace Square1\Mpp\Payment;

use Illuminate\Http\Request;
use Square1\Mpp\Attributes\RequiresPayment;
use Square1\Mpp\Exceptions\InvalidConfigurationException;

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
                (string) $entry['amount'],
                strtoupper($entry['currency'] ?? $this->defaultCurrency()),
                (int) ($options['grants'] ?? $entry['grants'] ?? $this->defaultGrants()),
                $options['scope'] ?? $args[0],
                $options['method'] ?? null,
                $request,
                isset($options['methods'])
                    ? array_values(array_filter(array_map('trim', explode('|', $options['methods']))))
                    : (isset($entry['methods']) ? (array) $entry['methods'] : null),
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
        $amount = $positional[0] ?? $this->defaultAmount();

        if ($amount === null || $amount === '') {
            throw new InvalidConfigurationException(
                'The mpp middleware needs an amount: give one inline (e.g. mpp:0.50,USD), set a '
                .'global default (MPP_DEFAULT_AMOUNT / mpp.defaults.amount), reference a price_book '
                .'key, or use a #[RequiresPayment] attribute.'
            );
        }

        // A per-route override: `methods=stripe|other` (pipe-separated, ordered).
        $methods = isset($options['methods']) && $options['methods'] !== ''
            ? array_values(array_filter(array_map('trim', explode('|', $options['methods']))))
            : null;

        return $this->build(
            (string) $amount,
            strtoupper($positional[1] ?? $this->defaultCurrency()),
            (int) ($options['grants'] ?? $this->defaultGrants()),
            $options['scope'] ?? null,
            $options['method'] ?? null,
            $request,
            $methods,
        );
    }

    public function fromAttribute(RequiresPayment $attribute, Request $request): PaymentSpec
    {
        $amount = $attribute->amount ?? $this->defaultAmount();

        if ($amount === null || $amount === '') {
            throw new InvalidConfigurationException(
                'A #[RequiresPayment] attribute needs an amount, or a global default '
                .'(MPP_DEFAULT_AMOUNT / mpp.defaults.amount).'
            );
        }

        return $this->build(
            (string) $amount,
            strtoupper($attribute->currency ?? $this->defaultCurrency()),
            $attribute->grants ?? $this->defaultGrants(),
            $attribute->scope,
            $attribute->method,
            $request,
            $attribute->methods,
        );
    }

    /**
     * @param  list<string>|null  $methods  explicit per-route ordered method set, or null to use config defaults
     */
    private function build(string $amount, string $currency, int $grants, ?string $scope, ?string $method, Request $request, ?array $methods = null): PaymentSpec
    {
        $offered = $this->resolveOfferedMethods($method, $methods);
        $primary = $offered[0];
        $methodConfig = config("mpp.methods.{$primary}", []);

        return new PaymentSpec(
            amount: $amount,
            currency: $currency,
            grants: max(1, $grants),
            scope: $scope ?: $this->defaultScope($request),
            method: $primary,
            networkId: $methodConfig['network_id'] ?? null,
            paymentMethodTypes: $methodConfig['payment_method_types'] ?? ['card'],
            offeredMethods: $offered,
        );
    }

    /**
     * Resolve the ordered set of offered methods, primary first.
     *
     * Precedence for the SET:
     *   1. an explicit per-route `$methods` list (middleware `methods=…` or the
     *      attribute's `methods:` param);
     *   2. an explicit single `$method` (middleware `method=…` or the
     *      attribute's `method:` param);
     *   3. otherwise the configured default offered set: `config('mpp.accept')`
     *      if present;
     *   4. otherwise just `config('mpp.default_method')`.
     *
     * Precedence for the PRIMARY (hoisted to the front of the set):
     *   1. the explicit single `$method` if given;
     *   2. otherwise, when the author gave an explicit ordered `$methods` list,
     *      its FIRST entry — the list is documented as "ordered, primary first",
     *      so the author's order wins over the configured default;
     *   3. otherwise `config('mpp.default_method')` if it is in the set (so a
     *      `config('mpp.accept')` set still defers to the configured default),
     *      else the set's first entry.
     *
     * A single-method result is byte-identical to the pre-multi-rail behaviour.
     *
     * @param  list<string>|null  $methods
     * @return list<string> non-empty, ordered, primary first, de-duplicated
     */
    private function resolveOfferedMethods(?string $method, ?array $methods): array
    {
        $default = config('mpp.default_method', 'stripe');
        $explicitList = is_array($methods) && $methods !== [];

        if ($explicitList) {
            $offered = $methods;
        } elseif ($method !== null && $method !== '') {
            $offered = [$method];
        } else {
            $accept = config('mpp.accept');
            $offered = is_array($accept) && $accept !== [] ? $accept : [$default];
        }

        if ($offered === []) {
            $offered = [$default];
        }

        // De-duplicate while preserving order.
        $offered = array_values(array_unique(array_map('strval', $offered)));

        // Choose the primary and hoist it to the front. An explicit `methods=`
        // list is ordered by the author, so its first entry is the primary; only
        // when the set comes from config (`accept`/`default_method`) does the
        // configured `default_method` win.
        if ($method !== null && $method !== '') {
            $primary = $method;
        } elseif ($explicitList) {
            $primary = $offered[0];
        } else {
            $primary = in_array($default, $offered, true) ? $default : $offered[0];
        }

        if (! in_array($primary, $offered, true)) {
            array_unshift($offered, $primary);
        } else {
            $offered = array_values(array_filter($offered, fn ($m) => $m !== $primary));
            array_unshift($offered, $primary);
        }

        return $offered;
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
        $amount = config('mpp.defaults.amount');

        return ($amount === null || $amount === '') ? null : (string) $amount;
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
