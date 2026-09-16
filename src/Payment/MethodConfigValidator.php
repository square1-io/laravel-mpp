<?php

namespace Square1\Mpp\Payment;

use Illuminate\Support\Facades\Log;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Settlement\StripeVerifier;
use Square1\Mpp\Settlement\TempoVerifier;

/**
 * Fails early on a settlement rail that the config does not fully describe.
 *
 * The class runs at the gate, before the gate mints a challenge, for every
 * method that a route offers. The check uses the configured *verifier* of the
 * rail as its key. It therefore applies only to the two rails that the package
 * ships, which are Stripe and Tempo. A custom verifier, or a test verifier,
 * validates its own configuration, and this class skips it.
 *
 * The severity depends on what a missing value breaks:
 *
 *   REQUIRED    A missing value makes the minted 402 itself malformed or
 *               unpayable. A Tempo challenge with no recipient tells the client
 *               to pay nobody. A Stripe challenge with no network_id omits a
 *               methodDetails member that the Stripe charge method requires. The
 *               class throws InvalidConfigurationException. The misconfiguration
 *               then appears on the first request. It does not appear later as a
 *               settlement failure that is hard to diagnose, or not at all.
 *
 *   RECOMMENDED The challenge is well-formed and payable, but settlement will
 *               not work. Examples are a missing Stripe secret key and a missing
 *               Tempo JSON-RPC endpoint. The class logs a warning once per
 *               process, and never throws. A site owner can therefore emit the
 *               402 now and set the key to settle later.
 */
class MethodConfigValidator
{
    /** The other config keys that can hold a value. */
    private const ALIASES = [
        'token' => ['currency'],
        'rpc_url' => ['rpc'],
    ];

    /**
     * The config keys that go on the wire as a JSON array.
     *
     * A scalar value for one of these keys is as incorrect as an absent value.
     * It would make methodDetails non-conformant.
     */
    private const LIST_KEYS = ['payment_method_types'];

    /** @var array<string, bool> The "method.key" flags that this process has warned about. */
    private array $warned = [];

    /**
     * Validates every method that a resolved spec offers.
     */
    public function validate(PaymentSpec $spec): void
    {
        foreach ($spec->offeredMethods as $method) {
            $this->validateMethod($method);
        }
    }

    public function validateMethod(string $method): void
    {
        // The method identifier goes on the wire as the `method` of the
        // challenge. The core draft restricts it to one or more lower-case ASCII
        // letters (1*LOWERALPHA). A misconfigured custom rail therefore cannot
        // mint a challenge that is not conformant. This check runs before the
        // verifier check, so it also covers a custom rail that the rule table
        // does not list.
        if (preg_match('/^[a-z]+$/D', $method) !== 1) {
            throw new InvalidConfigurationException(
                "The payment method identifier '{$method}' is not valid. The MPP core spec "
                .'allows one or more lower-case ASCII letters (a-z), and nothing else.'
            );
        }

        $config = (array) config("mpp.methods.{$method}", []);
        $rule = $this->ruleFor($config['verifier'] ?? null);

        if ($rule === null) {
            return; // a custom or test verifier is responsible for its own config
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
     * Returns the required and recommended config for a rail that the package
     * ships, keyed on its verifier.
     *
     * @return array{required: list<string>, recommended: array<string, string>, env: array<string, string>}|null
     */
    private function ruleFor(mixed $verifier): ?array
    {
        return match ($verifier) {
            StripeVerifier::class => [
                // The challenge advertises both of these in its methodDetails, and
                // the Stripe charge method requires them. Without network_id, a Link
                // wallet or an agent wallet cannot restrict a Shared Payment Token
                // to this seller. Without payment_method_types, the wallet does not
                // know what it can grant. The secret key is a secret for settlement
                // only, so the 402 still mints without it, and a client can pay
                // it.
                'required' => ['network_id', 'payment_method_types'],
                'recommended' => [
                    'secret_key' => 'Stripe settlement fails until you set a secret key. The package still emits the 402 challenge. Set STRIPE_SECRET_KEY.',
                ],
                'env' => ['secret_key' => 'STRIPE_SECRET_KEY', 'network_id' => 'STRIPE_NETWORK_ID'],
            ],
            TempoVerifier::class => [
                // The mppx challenge advertises these values. Without them, a
                // client cannot pay the 402 that the gate emits.
                'required' => ['recipient', 'token', 'chain_id'],
                'recommended' => [
                    'rpc_url' => 'Tempo settlement cannot broadcast the transaction that the client signed without a JSON-RPC endpoint. Set TEMPO_RPC_URL.',
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

            // An env placeholder that nothing expanded, such as
            // "${STRIPE_SECRET_KEY}", is not a value.
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
            "This route offers the '%s' payment rail, and its configuration is incomplete. "
            .'The following required config is missing: %s. Set it in config/mpp.php, under '
            ."mpp.methods.%s. You can also remove '%s' from the methods that this route offers.",
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

        Log::warning("[mpp] The '{$method}' rail is missing the recommended config '{$key}'. {$why}");
    }
}
