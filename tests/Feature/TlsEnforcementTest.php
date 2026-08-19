<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Tests\Fakes\FakeVerifier;

beforeEach(fn () => FakeVerifier::reset());

it('refuses to issue a challenge over plain http when insecure is not allowed', function () {
    config()->set('mpp.allow_insecure', false);

    $this->withoutExceptionHandling()->get('/clip');
})->throws(InvalidConfigurationException::class, 'MPP requires HTTPS');

it('issues a challenge over http when insecure is explicitly allowed', function () {
    config()->set('mpp.allow_insecure', true);

    $this->get('/clip')->assertStatus(402);
});

it('issues a challenge over https regardless of the insecure flag', function () {
    config()->set('mpp.allow_insecure', false);

    // A request the server sees as HTTPS (isSecure() true) is allowed even with
    // the insecure escape hatch off.
    $this->get('https://localhost/clip')->assertStatus(402);
});

it('refuses to serve discovery over plain http when insecure is not allowed', function () {
    // The discovery draft requires TLS too; the same guard covers /openapi.json.
    config()->set('mpp.allow_insecure', false);

    $this->withoutExceptionHandling()->get('/openapi.json');
})->throws(InvalidConfigurationException::class, 'MPP requires HTTPS');

it('serves discovery over https with the insecure flag off', function () {
    config()->set('mpp.allow_insecure', false);

    $this->get('https://localhost/openapi.json')->assertOk();
});
