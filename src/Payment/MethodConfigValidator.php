<?php

namespace Square1\Mpp\Payment;

use Illuminate\Support\Facades\Log;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Settlement\StripeVerifier;
use Square1\Mpp\Settlement\TempoVerifier;

/**
 * Fails fast on an incompletely-configured settlement rail.
 *
 * Runs at the gate, before a challenge is minted, for every method a route
 * offers. The check is keyed on the rail's configured *verifier*, so it applies
 * only to the two shipped rails (Stripe, Tempo); a custom (or test) verifier is
 * left to validate its own configuration and is skipped here.
 *
 * Severity is split by what a missing value actually breaks:
 *
 *   REQUIRED    A missing value makes the minted 402 *itself* malformed or
 *               unpayable — e.g. a Tempo challenge with no recipient instructs
 *               the client to pay nobody, and a Stripe challenge with no
 *               network_id omits a methodDetails member the Stripe charge method
 *               requires. Throws InvalidConfigurationException so the
 *               misconfiguration surfaces on the first request, not as a
 *               confusing settlement failure later (or never).
 *
 *   RECOMMENDED The challenge is well-formed and payable, but settlement is
 *               impaired — no Stripe secret key, no Tempo JSON-RPC endpoint.
 *               Logged once per process as a warning, never fatal — so "emit the
 *               402 now, set the key to settle later" stays a valid workflow.
 */
class MethodConfigValidator
{
    /** Config keys a value may also be supplied under. */
    private const ALIASES = [
        'token' => ['currency'],
        'rpc_url' => ['rpc'],
    ];

    /**
     * Config keys that go on the wire as a JSON array, so a scalar is as wrong
     * as an absent value: it would render methodDetails unconformant.
     */
    private const LIST_KEYS = ['payment_method_types'];

    /** @var array<string, bool> "method.key" flags already warned this process. */
    private array $warned = [];

    /**
     * Validate every method a resolved spec offers.
     */
    public function validate(PaymentSpec $spec): void
    {
        foreach ($spec->offeredMethods as $method) {
            $this->validateMethod($method);
        }
    }

    public function validateMethod(string $method): void
    {
        // The method identifier goes on the wire as the challenge `method`. The
        // core draft restricts it to one or more lowercase ASCII letters
        // (1*LOWERALPHA), so a misconfigured custom rail cannot mint a
        // non-conformant challenge. Checked before the verifier, so it also
        // covers custom rails the rule table does not know.
        if (preg_match('/^[a-z]+$/D', $method) !== 1) {
            throw new InvalidConfigurationException(
                "The payment method identifier '{$method}' is not valid. The MPP core spec "
                .'restricts method identifiers to one or more lowercase ASCII letters (a-z).'
            );
        }

        $config = (array) config("mpp.methods.{$method}", []);
        $rule = $this->ruleFor($config['verifier'] ?? null);

        if ($rule === null) {
            return; // custom / test verifier — responsible for its own config
        }

        $missing = array_values(array_filter(
            $rule['required'],
            fn (string $key): bool => ! $this->present($config, $key),
        ));

        if ($missing !== []) {
            throw new InvalidConfigurationException($this->missingMessage($method, $missing, $rule['env']));
        }

        foreach ($rule['recommended'] as $key => $why) {
            if (! $this->present($config, $key)) {
                $this->warnOnce($method, $key, $why);
            }
        }
    }

    /**
     * The required/recommended config for a shipped rail, keyed on its verifier.
     *
     * @return array{required: list<string>, recommended: array<string, string>, env: array<string, string>}|null
     */
    private function ruleFor(mixed $verifier): ?array
    {
        return match ($verifier) {
            StripeVerifier::class => [
                // Both of these are advertised in the challenge's methodDetails and
                // the Stripe charge method requires them: without network_id a Link
                // / agent wallet cannot scope a Shared Payment Token to this seller,
                // and without payment_method_types it does not know what it may
                // grant. The secret key is a settle-time secret only, so the 402
                // still mints (and is payable) without it.
                'required' => ['network_id', 'payment_method_types'],
                'recommended' => [
                    'secret_key' => 'Stripe settlement will fail until a secret key is set; the 402 challenge is still emitted (set STRIPE_SECRET_KEY).',
                ],
                'env' => ['secret_key' => 'STRIPE_SECRET_KEY', 'network_id' => 'STRIPE_NETWORK_ID'],
            ],
            TempoVerifier::class => [
                // These are advertised in the mppx challenge; without them the
                // emitted 402 cannot be paid.
                'required' => ['recipient', 'token', 'chain_id'],
                'recommended' => [
                    'rpc_url' => 'Tempo settlement cannot broadcast the client-signed transaction without a JSON-RPC endpoint (set TEMPO_RPC_URL).',
                ],
                'env' => [
                    'recipient' => 'TEMPO_RECIPIENT',
                    'token' => 'TEMPO_TOKEN',
                    'chain_id' => 'TEMPO_CHAIN_ID',
                    'rpc_url' => 'TEMPO_RPC_URL',
                ],
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function present(array $config, string $key): bool
    {
        $mustBeList = in_array($key, self::LIST_KEYS, strict: true);

        foreach (array_merge([$key], self::ALIASES[$key] ?? []) as $candidate) {
            if (! array_key_exists($candidate, $config) || $this->isBlank($config[$candidate])) {
                continue;
            }

            if ($mustBeList && ! is_array($config[$candidate])) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            return $value === [];
        }

        if (is_int($value)) {
            return $value === 0;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            // An unexpanded env placeholder ("${STRIPE_SECRET_KEY}") is not a value.
            return $trimmed === '' || str_starts_with($trimmed, '${');
        }

        return false;
    }

    /**
     * @param  list<string>  $missing
     * @param  array<string, string>  $env
     */
    private function missingMessage(string $method, array $missing, array $env): string
    {
        $pairs = array_map(
            fn (string $key): string => isset($env[$key]) ? "{$key} ({$env[$key]})" : $key,
            $missing,
        );

        return sprintf(
            "The '%s' payment rail is offered on this route but its configuration is incomplete. "
            .'Missing required config: %s — set it under config/mpp.php (mpp.methods.%s), '
            ."or remove '%s' from the methods this route offers.",
            $method,
            implode(', ', $pairs),
            $method,
            $method,
        );
    }

    private function warnOnce(string $method, string $key, string $why): void
    {
        $flag = "{$method}.{$key}";

        if (isset($this->warned[$flag])) {
            return;
        }

        $this->warned[$flag] = true;

        Log::warning("[mpp] The '{$method}' rail is missing recommended config '{$key}': {$why}");
    }
}
