<?php

namespace Square1\Mpp\Protocol\Requests;

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\PaymentSpec;

/**
 * The request payload for the tempo rail, in the shape of draft-tempo-charge.
 *
 * The payload carries the amount in the base units of the TIP-20 token, the
 * token address as `currency`, the settlement `recipient`, and, in
 * methodDetails, the chain id and the supported submission modes.
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
                "Amount '{$spec->amount}' has more decimal places than the {$decimals} that the tempo token allows."
            );
        }

        return [
            'amount' => $minor,
            'currency' => $token,
            'recipient' => $recipient,
            'methodDetails' => [
                'chainId' => $chainId,
                // This is pull only. TempoVerifier accepts a credential with
                // type="transaction", which carries a signed transaction for the
                // server to broadcast. It rejects every other credential. If the
                // builder omitted supportedModes, a conformant client could assume
                // that push also works, which is type="hash" with the client
                // broadcasting.
                'supportedModes' => ['pull'],
                // This is a random bytes32 memo per challenge. The builder
                // advertises it, so that a conformant client knows to pay with
                // transferWithMemo and to carry this exact value. The memo travels
                // in the `request` of the challenge, so the challenge id HMAC binds
                // it and no one can change it. The paid retry must present a
                // transfer whose memo equals this value. That binds the on-chain
                // payment to this one challenge, and the client needs no special
                // logic to derive a memo.
                'memo' => '0x'.bin2hex(random_bytes(32)),
            ],
        ];
    }
}
