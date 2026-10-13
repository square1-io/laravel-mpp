<?php

use Square1\Mpp\Protocol\Tempo\AttributionMemo;
use Square1\Mpp\Support\Evm\Keccak;

/**
 * The vectors come from `Attribution.encode()` in mppx 0.13.4: one anonymous,
 * and one with clientId "my-agent".
 */
const MPPX_ANON_MEMO = '0xef1ed7120135bfd7eb12e2daed83fd00000000000000000000d661e55c8d44f9';
const MPPX_ANON_CHALLENGE = 'tdCiAaAQPDdNOGahL56A343eGtfHlCVnbY99W_DMbZ8';

const MPPX_CLIENT_MEMO = '0xef1ed712011a12431d111f7f6a161d13f85972c343104031357e9789e152c5f5';
const MPPX_CLIENT_CHALLENGE = 'Pq8gaWhpZ4xJCZvy64Q4uq6RxofIAYHGWJCUPvOdfSY';

it('hard-codes the tag as keccak256("mpp")[0..3] and version 0x01', function () {
    expect(AttributionMemo::PREFIX)->toBe(substr(Keccak::hashHex('mpp'), 2, 8).'01');
});

it('matches an mppx memo for its challenge and realm, whatever the client id', function () {
    expect(AttributionMemo::matches(MPPX_ANON_MEMO, MPPX_ANON_CHALLENGE, 'localhost'))->toBeTrue()
        ->and(AttributionMemo::matches(MPPX_CLIENT_MEMO, MPPX_CLIENT_CHALLENGE, 'www.payforgoals.com.test'))->toBeTrue();
});

it('rejects a memo that does not bind this challenge and realm', function (string $memo, string $challengeId, string $realm) {
    expect(AttributionMemo::matches($memo, $challengeId, $realm))->toBeFalse();
})->with([
    'another challenge' => [MPPX_ANON_MEMO, MPPX_CLIENT_CHALLENGE, 'localhost'],
    'another realm' => [MPPX_ANON_MEMO, MPPX_ANON_CHALLENGE, 'api.example.com'],
    'wrong tag' => ['0x00000000'.substr(MPPX_ANON_MEMO, 10), MPPX_ANON_CHALLENGE, 'localhost'],
    'wrong version' => [substr(MPPX_ANON_MEMO, 0, 10).'02'.substr(MPPX_ANON_MEMO, 12), MPPX_ANON_CHALLENGE, 'localhost'],
    'too short' => ['0x1234', MPPX_ANON_CHALLENGE, 'localhost'],
    'empty' => ['', MPPX_ANON_CHALLENGE, 'localhost'],
]);
