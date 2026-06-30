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
 *               the client to pay nobody. Throws InvalidConfigurationException
 *               so the misconfiguration surfaces on the first request, not as a
 *               confusing settlement failure later (or never).
 *
 *   RECOMMENDED The challenge is well-formed and payable, but settlement is
 *               impaired (no Stripe secret key) or a buyer wallet cannot scope a
 *               token to this seller (no network_id). Logged once per process as
 *               a warning, never fatal — so "emit the 402 now, set the key to
 *               settle later" stays a valid workflow.
 */
class MethodConfigValidator
{
    /** Config keys a value may also be supplied under. */
    private const ALIASES = [
        'token' => ['currency'],
        'rpc_url' => ['rpc'],
    ];

    /** @var array<string, bool> "method.key" flags already warned this process. */
    private array $warned = [];

    /**
     * Validate every method a resolved spec offers, and that the offered set
     * doesn't mix wire dialects (see assertSingleDialect).
     */
    public function validate(PaymentSpec $spec): void
    {
        $this->assertSingleDialect($spec->offeredMethods);

        foreach ($spec->offeredMethods as $method) {
            $this->validateMethod($method);
        }
    }

    /**
     * A single 402 carries one wire dialect. The mppx-dialect rail (Tempo) emits
     * a base64 `request` blob with no signed accepts[], which a native/SPT agent
     * can't read — and a stock mppx agent can't read a native accepts[] entry. So
     * the mppx rail can't be co-offered alongside native rails: it must be the
     * sole/primary method. Fail fast rather than mint a 402 that silently drops a
     * rail (mppx primary) or lists an unpayable one (native primary).
     *
     * Keyed on the verifier, like the per-rail rules — a custom or test verifier
     * registered under a method name counts as native and is exempt.
     *
     * @param  list<string>  $offered
     */
    private function assertSingleDialect(array $offered): void
    {
        if (count($offered) < 2) {
            return;
        }

        $mppx = array_values(array_filter($offered, fn (string $m): bool => $this->isMppxDialect($m)));

        if ($mppx === []) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            "The '%s' rail speaks the mppx wire dialect and can't be co-offered with native rails "
            ."in one 402 (this route offers: %s). A 402 carries a single dialect, so make '%s' the "
            .'sole/primary method (e.g. method=%s, or default_method=%s), or choose the rail per '
            .'request. See "Offering several rails at once" in the README.',
            $mppx[0],
            implode('|', $offered),
            $mppx[0],
            $mppx[0],
            $mppx[0],
        ));
    }

    private function isMppxDialect(string $method): bool
    {
        return (config("mpp.methods.{$method}.verifier") ?? null) === TempoVerifier::class;
    }

    public function validateMethod(string $method): void
    {
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
                // The Stripe 402 mints (and is payable) without a secret key — the
                // key is a settle-time secret — so nothing here is fatal.
                'required' => [],
                'recommended' => [
                    'secret_key' => 'Stripe settlement will fail until a secret key is set; the 402 challenge is still emitted (set STRIPE_SECRET_KEY).',
                    'network_id' => 'a Link / agent wallet cannot scope a Shared Payment Token to this seller without the MPP Network/Profile ID advertised in the 402 (set STRIPE_NETWORK_ID, a profile_… created in the Stripe Dashboard).',
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
        foreach (array_merge([$key], self::ALIASES[$key] ?? []) as $candidate) {
            if (array_key_exists($candidate, $config) && ! $this->isBlank($config[$candidate])) {
                return true;
            }
        }

        return false;
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
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
