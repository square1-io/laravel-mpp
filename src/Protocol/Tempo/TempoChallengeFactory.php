<?php

namespace Square1\Mpp\Protocol\Tempo;

use Carbon\CarbonImmutable;
use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Support\Money;

/**
 * Mints mppx-dialect (tempo) challenges.
 *
 * The challenge id is an HMAC-SHA256 (base64url, no padding) over the binding
 * fields — realm, method, intent, the canonical request blob (amount, token,
 * recipient, chainId) and the expiry. This makes the id self-authenticating in
 * the mppx spirit (a client cannot alter the price/token/recipient between the
 * 402 and the paid retry without invalidating the id) AND it is persisted in the
 * {@see TempoChallengeStore} so the challenge is single-use and looked up on
 * retry. The same secret the package already uses (`mpp.secret`) signs it.
 *
 * The client derives its on-chain attribution memo nonce from this id
 * (`keccak256(id)[0..6]`), so the id is the load-bearing anchor for the binding.
 */
final class TempoChallengeFactory
{
    public function __construct(
        private readonly MppxCodec $codec,
        private readonly string $secret,
        private readonly int $ttl = 300,
    ) {
        if ($this->secret === '') {
            throw new InvalidConfigurationException(
                'mpp.secret is not set. Provide MPP_CHALLENGE_SECRET before minting challenges.'
            );
        }
    }

    /**
     * Build a tempo challenge from a resolved PaymentSpec and the rail config.
     *
     * @param  array<string, mixed>  $methodConfig  the `mpp.methods.tempo` config block
     */
    public function mint(PaymentSpec $spec, array $methodConfig, string $realm, ?CarbonImmutable $now = null): TempoChallengeState
    {
        $now ??= CarbonImmutable::now();

        // Preserve the configured casing of the token/recipient in the request
        // blob (matching the mppx reference, which passes them through). All
        // downstream comparisons are case-insensitive, and EVM addresses are
        // case-insensitive on-chain, so casing never affects correctness.
        $token = (string) ($methodConfig['token'] ?? $methodConfig['currency'] ?? '');
        $recipient = (string) ($methodConfig['recipient'] ?? '');
        $chainId = (int) ($methodConfig['chain_id'] ?? 0);
        $decimals = (int) ($methodConfig['decimals'] ?? 6);

        if ($token === '') {
            throw new InvalidConfigurationException('mpp.methods.tempo.token (or .currency) is not configured.');
        }
        if ($recipient === '') {
            throw new InvalidConfigurationException('mpp.methods.tempo.recipient is not configured.');
        }
        if ($chainId === 0) {
            throw new InvalidConfigurationException('mpp.methods.tempo.chain_id is not configured.');
        }

        $amount = $this->toTokenMinorUnits($spec->amount, $decimals);
        $expiresAt = $now->addSeconds($this->ttl);

        // Build the state first (without id) so we can canonicalize its request.
        $bindingState = new TempoChallengeState(
            id: '',
            realm: $realm,
            amount: $amount,
            token: $token,
            recipient: $recipient,
            chainId: $chainId,
            expiresAt: $expiresAt,
            grants: $spec->grants,
            scope: $spec->scope,
        );

        $id = $this->computeId($bindingState);

        return new TempoChallengeState(
            id: $id,
            realm: $realm,
            amount: $amount,
            token: $token,
            recipient: $recipient,
            chainId: $chainId,
            expiresAt: $expiresAt,
            grants: $spec->grants,
            scope: $spec->scope,
        );
    }

    /**
     * Recompute the stateless HMAC id for a challenge state. Used to verify that
     * an echoed challenge was issued by this server (defence-in-depth alongside
     * the store lookup), so a challenge minted under a rotated secret no longer
     * verifies.
     */
    public function computeId(TempoChallengeState $state): string
    {
        $input = implode('|', [
            $state->realm,
            'tempo',
            $state->intent,
            $this->codec->encodeRequest($state),
            $this->codec->canonicalize($state->expiresAt->utc()->format('Y-m-d\TH:i:s.v\Z')),
        ]);

        $mac = hash_hmac('sha256', $input, $this->secret, true);

        return $this->codec->base64UrlEncode($mac);
    }

    public function verifyId(TempoChallengeState $state): bool
    {
        return hash_equals($this->computeId($state), $state->id);
    }

    /**
     * Convert a decimal amount (e.g. "0.01") to token minor units at the token's
     * configured decimals (pathUSD = 6), as a decimal string. Pure string maths.
     */
    private function toTokenMinorUnits(string $amount, int $decimals): string
    {
        $amount = trim($amount);

        if ($amount === '' || ! preg_match('/^\d*(\.\d+)?$/', $amount)) {
            throw new InvalidConfigurationException("Invalid tempo amount: '{$amount}'.");
        }

        [$whole, $frac] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($frac) > $decimals) {
            throw new InvalidConfigurationException(
                "Tempo amount '{$amount}' has more than {$decimals} decimal places."
            );
        }

        $frac = substr(str_pad($frac, $decimals, '0'), 0, $decimals);

        $minor = ltrim($whole.$frac, '0');

        return $minor === '' ? '0' : $minor;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /** Expose Money for callers that need decimal/minor conversions in USD terms. */
    public function usdMinor(string $amount, string $currency): int
    {
        return Money::toMinorUnits($amount, $currency);
    }
}
