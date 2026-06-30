<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Protocol\ChallengeFactory;
use Square1\Mpp\Protocol\Tempo\TempoChallengeFactory;

it('mints a 402 with a key derived from APP_KEY when MPP_CHALLENGE_SECRET is unset', function () {
    config()->set('mpp.secret', '');
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    // The factories are singletons resolved on first use — drop any so they
    // rebuild with the new config through the service provider's resolver.
    app()->forgetInstance(ChallengeFactory::class);
    app()->forgetInstance(TempoChallengeFactory::class);

    $this->getJson('/clip')->assertStatus(402);     // /clip is a native (stripe) route
});

it('refuses to boot the signer when neither MPP_CHALLENGE_SECRET nor APP_KEY is set', function () {
    config()->set('mpp.secret', '');
    config()->set('app.key', '');

    app()->forgetInstance(ChallengeFactory::class);

    $this->withoutExceptionHandling();
    $this->getJson('/clip');
})->throws(InvalidConfigurationException::class);
