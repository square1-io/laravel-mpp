<?php

namespace Square1\Mpp\Protocol\Requests;

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\PaymentSpec;

/**
 * Request payload for the tempo rail (draft-tempo-charge shape): amount in the
 * TIP-20 token's base units, the token address as `currency`, the settlement
 * `recipient`, and the chain id + supported submission modes in methodDetails.
 */
class TempoRequestBuilder implements RailRequestBuilder
{
    public function build(PaymentSpec $spec, array $config): array
    {
        $recipient = (string) ($config['recipient'] ?? '');
        $token = (string) ($config['token'] ?? $config['currency'] ?? '');
        $chainId = (int) ($config['chain_id'] ?? 0);

        if ($recipient === '' || $token === '' || $chainId === 0) {
            throw new InvalidConfigurationException(
                'The tempo rail needs recipient, token and chain_id configured before a challenge can be minted.'
            );
        }

        $decimals = (int) ($config['decimals'] ?? 6);
        $scaled = bcmul((string) $spec->amount, bcpow('10', (string) $decimals), 8);

        if (bccomp($scaled, $minor = bcadd($scaled, '0', 0), 8) !== 0) {
            throw new InvalidConfigurationException(
                "Amount '{$spec->amount}' has more precision than the tempo token's {$decimals} decimals."
            );
        }

        return [
            'amount' => $minor,
            'currency' => $token,
            'recipient' => $recipient,
            'methodDetails' => [
                'chainId' => $chainId,
                // Pull only: TempoVerifier accepts a type="transaction" credential
                // carrying a signed transaction for us to broadcast, and rejects
                // everything else. Omitting supportedModes would let a conformant
                // client assume push (type="hash", client broadcasts) works too.
                'supportedModes' => ['pull'],
                // A random per-challenge bytes32 memo, advertised so a conformant
                // client knows to pay with transferWithMemo carrying this exact
                // value. It rides in the challenge `request`, so it is bound into
                // the challenge id HMAC and cannot be swapped. The paid retry must
                // present a transfer whose memo equals it, which binds the on-chain
                // payment to this one challenge without any bespoke client-side
                // memo derivation.
                'memo' => '0x'.bin2hex(random_bytes(32)),
            ],
        ];
    }
}
