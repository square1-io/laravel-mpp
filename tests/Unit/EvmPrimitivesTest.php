<?php

use Square1\Mpp\Support\Evm\Attribution;
use Square1\Mpp\Support\Evm\Keccak;
use Square1\Mpp\Support\Evm\Rlp;
use Square1\Mpp\Support\Evm\TempoTransaction;

/**
 * The pure-PHP EVM primitives the Tempo settlement path relies on, pinned to
 * known fixtures (so a regression in the hash/RLP/decoders is caught here, not
 * only transitively in the verifier tests).
 */
it('computes Ethereum Keccak-256 (not SHA3-256)', function () {
    // Empty input — the canonical Keccak-256 test vector.
    expect(Keccak::hashHex(''))->toBe('0xc5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470')
        // keccak256("mpp")[0..4] is the MPP attribution tag.
        ->and(substr(Keccak::hashHex('mpp'), 0, 10))->toBe('0xef1ed712');
});

it('decodes RLP lists and strings', function () {
    // "dog" -> 0x83646f67
    expect(Rlp::decode('0x83646f67'))->toBe('0x646f67');
    // ["cat","dog"] -> 0xc88363617483646f67
    expect(Rlp::decode('0xc88363617483646f67'))->toBe(['0x636174', '0x646f67']);
    // empty string -> 0x80
    expect(Rlp::decode('0x80'))->toBe('0x');
});

it('recognises Tempo (0x76/0x78) envelopes and rejects others', function () {
    expect(TempoTransaction::isTempoTransaction('0x76abcd'))->toBeTrue()
        ->and(TempoTransaction::isTempoTransaction('0x78abcd'))->toBeTrue()
        ->and(TempoTransaction::isTempoTransaction('0x02abcd'))->toBeFalse(); // EIP-1559
});

it('decodes a transferWithMemo call from real calldata', function () {
    $call = [
        'to' => '0x20c0000000000000000000000000000000000000',
        'value' => null,
        'data' => '0x95777d590000000000000000000000000dcd39a3f85aa288c1b2825bc41eb7e9bb2abf700000000000000000000000000000000000000000000000000000000000002710ef1ed7120135bfd7eb12e2daed83fd00000000000000000000d661e55c8d44f9',
    ];

    $decoded = TempoTransaction::decodeTransferCall($call, '0x20c0000000000000000000000000000000000000');

    expect($decoded['recipient'])->toBe('0x0dcd39a3f85aa288c1b2825bc41eb7e9bb2abf70')
        ->and($decoded['amount'])->toBe('10000')
        ->and($decoded['memo'])->toBe('0xef1ed7120135bfd7eb12e2daed83fd00000000000000000000d661e55c8d44f9');
});

it('returns null when a call targets a different token', function () {
    $call = ['to' => '0x1111111111111111111111111111111111111111', 'value' => null, 'data' => '0x95777d59'];

    expect(TempoTransaction::decodeTransferCall($call, '0x20c0000000000000000000000000000000000000'))->toBeNull();
});

it('verifies and rejects MPP attribution memo bindings against fixtures', function () {
    $memo = '0xef1ed7120135bfd7eb12e2daed83fd00000000000000000000d661e55c8d44f9';
    $challengeId = 'tdCiAaAQPDdNOGahL56A343eGtfHlCVnbY99W_DMbZ8';

    expect(Attribution::isMppMemo($memo))->toBeTrue()
        ->and(Attribution::verifyServer($memo, 'localhost'))->toBeTrue()
        ->and(Attribution::verifyServer($memo, 'evil.example.com'))->toBeFalse()
        ->and(Attribution::verifyChallengeBinding($memo, $challengeId))->toBeTrue()
        ->and(Attribution::verifyChallengeBinding($memo, 'a-different-challenge'))->toBeFalse()
        ->and(Attribution::isMppMemo('0x'.str_repeat('00', 32)))->toBeFalse();
});
