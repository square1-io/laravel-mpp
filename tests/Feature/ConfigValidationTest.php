<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Settlement\TempoVerifier;

it('bails out at the gate when an offered rail is misconfigured', function () {
    // Swap the /tempo route's fake verifier for the real one, then strip the
    // config it needs to mint a payable challenge. The gate must refuse before
    // minting, not emit a broken 402.
    config()->set('mpp.methods.tempo.verifier', TempoVerifier::class);
    config()->set('mpp.methods.tempo.recipient', null);
    config()->set('mpp.methods.tempo.token', null);
    config()->set('mpp.methods.tempo.chain_id', null);

    $this->withoutExceptionHandling();
    $this->getJson('/tempo');
})->throws(InvalidConfigurationException::class, 'tempo');

it('still emits a Stripe 402 with no secret key set (recommended config only warns)', function () {
    // /clip offers stripe via the FakeVerifier in the base TestCase, so this
    // also confirms a custom verifier is exempt from the rail checks.
    $this->getJson('/clip')->assertStatus(402);
});
