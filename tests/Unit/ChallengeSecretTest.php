<?php

use Square1\Mpp\Exceptions\InvalidConfigurationException;
use Square1\Mpp\Support\ChallengeSecret;

it('uses an explicit secret when one is set', function () {
    expect(ChallengeSecret::resolve('my-explicit-secret', 'base64:whatever'))
        ->toBe('my-explicit-secret');
});

it('prefers and trims the explicit secret over the app key', function () {
    expect(ChallengeSecret::resolve('  spaced-secret  ', 'app-key'))
        ->toBe('spaced-secret');
});

it('derives a key from APP_KEY when no explicit secret is set', function () {
    $appKey = 'base64:'.base64_encode(str_repeat('k', 32));

    $derived = ChallengeSecret::resolve('', $appKey);

    expect($derived)
        ->toBe(hash_hmac('sha256', 'laravel-mpp:challenge-v1', $appKey))
        ->and($derived)->not->toBe($appKey);          // never the raw app key
});

it('derives deterministically across calls (stable signer)', function () {
    $appKey = 'base64:'.base64_encode(str_repeat('z', 32));

    expect(ChallengeSecret::resolve(null, $appKey))
        ->toBe(ChallengeSecret::resolve('', $appKey));
});

it('throws when neither a secret nor an app key is available', function () {
    ChallengeSecret::resolve('', '');
})->throws(InvalidConfigurationException::class);

it('throws when both are only whitespace', function () {
    ChallengeSecret::resolve('   ', null);
})->throws(InvalidConfigurationException::class);
