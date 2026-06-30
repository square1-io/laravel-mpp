<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Payment\PaymentSpec;
use Square1\Mpp\Protocol\Tempo\MppxCodec;
use Square1\Mpp\Protocol\Tempo\TempoChallengeFactory;

function tempoFactory(): TempoChallengeFactory
{
    return new TempoChallengeFactory(new MppxCodec, 'unit-test-secret');
}

function tempoMethodConfig(array $overrides = []): array
{
    return array_merge([
        'token' => '0x20c0000000000000000000000000000000000000',
        'recipient' => '0x0dcd39a3f85aa288c1b2825bc41eb7e9bb2abf70',
        'chain_id' => 42431,
        'decimals' => 6,
    ], $overrides);
}

it('rejects tempo amounts with more precision than the token supports', function () {
    tempoFactory()->mint(
        new PaymentSpec(
            amount: '0.0000009',
            currency: 'USD',
            grants: 1,
            scope: 'tempo.clip',
            method: 'tempo',
        ),
        tempoMethodConfig(),
        'localhost',
    );
})->throws(InvalidConfigurationException::class);
