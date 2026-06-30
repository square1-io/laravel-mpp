<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\Tempo\MppxCodec;
use Square1\Mpp\Protocol\Tempo\ParsedTempoCredential;
use Square1\Mpp\Protocol\Tempo\TempoChallengeState;
use Square1\Mpp\Settlement\TempoRpcSettlementChecker;
use Square1\Mpp\Settlement\TempoVerifier;
use Square1\Mpp\Tests\Fakes\FakeRpcClient;

/**
 * These tests assert the real mppx model against the EXACT captured exchange:
 * the verifier decodes the real signed transaction, validates recipient/amount/
 * token + the challenge-bound memo against the issued challenge, broadcasts it
 * through a fake RPC client, and returns the tx hash — and fails closed on every
 * mismatch.
 */

// The exact base64url credential captured from a stock `npx mppx … --network testnet`.
const CAPTURED_CREDENTIAL = 'eyJjaGFsbGVuZ2UiOnsiZXhwaXJlcyI6IjIwMjYtMDYtMjJUMTU6MjY6MzMuMTU4WiIsImlkIjoidGRDaUFhQVFQRGROT0dhaEw1NkEzNDNlR3RmSGxDVm5iWTk5V19ETWJaOCIsImludGVudCI6ImNoYXJnZSIsIm1ldGhvZCI6InRlbXBvIiwicmVhbG0iOiJsb2NhbGhvc3QiLCJyZXF1ZXN0IjoiZXlKaGJXOTFiblFpT2lJeE1EQXdNQ0lzSW1OMWNuSmxibU41SWpvaU1IZ3lNR013TURBd01EQXdNREF3TURBd01EQXdNREF3TURBd01EQXdNREF3TURBd01EQXdNREF3SWl3aWJXVjBhRzlrUkdWMFlXbHNjeUk2ZXlKamFHRnBia2xrSWpvME1qUXpNWDBzSW5KbFkybHdhV1Z1ZENJNklqQjRNR1JqWkRNNVFUTkdPRFZoWVRJNE9FTXhRakk0TWpWaVl6UXhSVUkzWlRsQ1FqSkJRa1kzTUNKOSJ9LCJwYXlsb2FkIjp7InNpZ25hdHVyZSI6IjB4NzZmOTAxMDA4MmE1YmY4MzBmNDI0MDg1MDU5Njk1M2Y4MDgzMDEzOTg5Zjg3ZWY4N2M5NDIwYzAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA4MGI4NjQ5NTc3N2Q1OTAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDBkY2QzOWEzZjg1YWEyODhjMWIyODI1YmM0MWViN2U5YmIyYWJmNzAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAyNzEwZWYxZWQ3MTIwMTM1YmZkN2ViMTJlMmRhZWQ4M2ZkMDAwMDAwMDAwMDAwMDAwMDAwMDBkNjYxZTU1YzhkNDRmOWMwYTBmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmODA4NDZhMzk1MzE2ODA4MDgwYzBiODQxNWE4MzdhMmUzNWEyZWNlZjg2NGY2ODM0NDc1YTc4YzU0NzA4ZWYwZDEwY2M2NDhlYzlmNjg1MzFjNDU4ODhlNzMxODIxYzA3YTk2YjE4ODZlNzFlMmJjZjZjMjliYmUwNmYzZGZiOGI4MTkwYzYyMzE4MWU0Y2Y3YzEwNTAyYWMxYyIsInR5cGUiOiJ0cmFuc2FjdGlvbiJ9LCJzb3VyY2UiOiJkaWQ6cGtoOmVpcDE1NTo0MjQzMToweGNDQ0Q5NDdhYTVlZjYyNDhGZUJkM0FBYTQxYzA3ZDhiMGU5YjNGZTEifQ';

const CAPTURED_CHALLENGE_ID = 'tdCiAaAQPDdNOGahL56A343eGtfHlCVnbY99W_DMbZ8';
const CAPTURED_TX_HASH = '0xe6f620fd235aafbb18d570fc798bb0a556189025608e99e1d3b21ab49d511544';
const PATH_USD = '0x20c0000000000000000000000000000000000000';
const RECIPIENT = '0x0dcd39a3f85aa288c1b2825bc41eb7e9bb2abf70';

function capturedCredential(): ParsedTempoCredential
{
    return (new MppxCodec)->parseCredential('Payment '.CAPTURED_CREDENTIAL);
}

function tempoState(array $overrides = []): TempoChallengeState
{
    return new TempoChallengeState(
        id: $overrides['id'] ?? CAPTURED_CHALLENGE_ID,
        realm: $overrides['realm'] ?? 'localhost',
        amount: $overrides['amount'] ?? '10000',
        token: $overrides['token'] ?? PATH_USD,
        recipient: $overrides['recipient'] ?? RECIPIENT,
        chainId: $overrides['chainId'] ?? 42431,
        expiresAt: $overrides['expiresAt'] ?? CarbonImmutable::now()->addMinutes(5),
        grants: $overrides['grants'] ?? 1,
    );
}

function tempoVerifier(FakeRpcClient $rpc, array $config = []): TempoVerifier
{
    return new TempoVerifier(
        new TempoRpcSettlementChecker($rpc),
        array_merge(['confirmations' => 1, 'poll_attempts' => 1, 'poll_delay_ms' => 0], $config),
    );
}

it('decodes the captured credential and extracts payer + signed transaction', function () {
    $credential = capturedCredential();

    expect($credential->challengeId)->toBe(CAPTURED_CHALLENGE_ID)
        ->and($credential->realm)->toBe('localhost')
        ->and($credential->isTransaction())->toBeTrue()
        ->and($credential->signature)->toStartWith('0x76')
        ->and($credential->source)->toBe('did:pkh:eip155:42431:0xcCCD947aa5ef6248FeBd3AAa41c07d8b0e9b3Fe1')
        ->and((new MppxCodec)->payerFromSource($credential->source))->toBe('0xcccd947aa5ef6248febd3aaa41c07d8b0e9b3fe1');
});

it('settles the captured credential: validates token/amount/recipient + memo, broadcasts, returns the tx hash', function () {
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $rpc->forcedHash = CAPTURED_TX_HASH;

    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState());

    expect($result->succeeded)->toBeTrue()
        ->and($result->settlementRef)->toBe(CAPTURED_TX_HASH)
        ->and($result->amountMinor)->toBe(10000)
        ->and($rpc->broadcasts)->toHaveCount(1);

    // It broadcast the EXACT signed bytes the client presented — never re-signs.
    expect($rpc->broadcasts[0])->toBe(capturedCredential()->signature);
});

it('fails closed on a wrong amount', function () {
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState(['amount' => '99999']));

    // Caught at the echoed-request guard (the credential echoes 10000, the stored
    // challenge requires 99999) — a pre-broadcast mismatch, never broadcast.
    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toContain('does not match')
        ->and($rpc->broadcasts)->toBeEmpty();
});

it('fails closed on a wrong recipient', function () {
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $attacker = '0x000000000000000000000000000000000000dead';
    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState(['recipient' => $attacker]));

    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toContain('does not match')
        ->and($rpc->broadcasts)->toBeEmpty();
});

it('fails closed when the signed tx pays the wrong recipient (forged echo, mismatched tx)', function () {
    // The echoed request is consistent with the stored challenge, but the signed
    // transaction itself pays a different recipient than the (attacker-chosen)
    // stored recipient — caught by the transfer-call decode before broadcast.
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $real = capturedCredential();
    $attacker = '0x000000000000000000000000000000000000dead';
    $forged = new ParsedTempoCredential(
        challengeId: $real->challengeId,
        realm: $real->realm,
        method: $real->method,
        intent: $real->intent,
        expires: $real->expires,
        // Echo a request consistent with a challenge that pays the attacker...
        request: ['amount' => '10000', 'currency' => PATH_USD, 'recipient' => $attacker, 'methodDetails' => ['chainId' => 42431]],
        payloadType: $real->payloadType,
        signature: $real->signature, // ...but the signed tx pays RECIPIENT, not the attacker.
        source: $real->source,
        rawRequest: $real->rawRequest,
    );

    $result = tempoVerifier($rpc)->verifyTempo($forged, tempoState(['recipient' => $attacker]));

    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toContain('No transfer call')
        ->and($rpc->broadcasts)->toBeEmpty();
});

it('fails closed on a wrong token', function () {
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $other = '0x1111111111111111111111111111111111111111';
    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState(['token' => $other]));

    expect($result->succeeded)->toBeFalse()->and($rpc->broadcasts)->toBeEmpty();
});

it('fails closed when the memo is bound to a different challenge id', function () {
    // Same economics, but a DIFFERENT challenge id — the memo nonce will not match.
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState(['id' => 'some-other-challenge-id']));

    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toContain('not bound to this challenge')
        ->and($rpc->broadcasts)->toBeEmpty();
});

it('fails closed when the realm does not match the memo server fingerprint', function () {
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState(['realm' => 'evil.example.com']));

    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toContain('realm mismatch')
        ->and($rpc->broadcasts)->toBeEmpty();
});

it('fails closed on an expired challenge', function () {
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState(['expiresAt' => CarbonImmutable::now()->subMinute()]));

    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toContain('expired')
        ->and($rpc->broadcasts)->toBeEmpty();
});

it('fails closed on a reverted (status 0x0) receipt', function () {
    $receipt = FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH);
    $receipt['status'] = '0x0';
    $rpc = new FakeRpcClient($receipt);

    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('reverted');
});

it('fails closed when the receipt is absent (never mined)', function () {
    $rpc = new FakeRpcClient(null); // getTransactionReceipt returns null

    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('not mined');
});

it('fails closed when the mined receipt has no matching transfer log', function () {
    // Receipt mined OK but pays a different recipient — defence-in-depth catches it.
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, '0x000000000000000000000000000000000000beef', '10000', CAPTURED_TX_HASH));

    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('No matching');
});

it('fails closed on insufficient confirmations', function () {
    // Mined at block 100, head at 100 => depth 1, but we require 5.
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH, 100));
    $rpc->head = 100;

    $result = tempoVerifier($rpc, ['confirmations' => 5])->verifyTempo(capturedCredential(), tempoState());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('finality');
});

it('fails closed when the broadcast fails and the hash cannot be confirmed', function () {
    $rpc = new FakeRpcClient(null);
    $rpc->broadcastError = 'insufficient funds';

    $result = tempoVerifier($rpc)->verifyTempo(capturedCredential(), tempoState());

    expect($result->succeeded)->toBeFalse();
});

it('rejects a non-transaction payload type', function () {
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $credential = new ParsedTempoCredential(
        challengeId: CAPTURED_CHALLENGE_ID,
        realm: 'localhost',
        method: 'tempo',
        intent: 'charge',
        expires: null,
        request: ['amount' => '10000', 'currency' => PATH_USD, 'recipient' => RECIPIENT, 'methodDetails' => ['chainId' => 42431]],
        payloadType: 'hash',
        signature: CAPTURED_TX_HASH,
        source: null,
        rawRequest: null,
    );

    $result = tempoVerifier($rpc)->verifyTempo($credential, tempoState());

    expect($result->succeeded)->toBeFalse()->and($result->failureReason)->toContain('Unsupported');
});

it('fails closed when the echoed request is tampered to a lower amount', function () {
    // Client echoes a request claiming amount 1, but the signed tx pays 10000 and
    // the stored challenge requires 10000 — the echoed-request guard rejects it.
    $rpc = new FakeRpcClient(FakeRpcClient::successReceipt(PATH_USD, RECIPIENT, '10000', CAPTURED_TX_HASH));
    $real = capturedCredential();
    $tampered = new ParsedTempoCredential(
        challengeId: $real->challengeId,
        realm: $real->realm,
        method: $real->method,
        intent: $real->intent,
        expires: $real->expires,
        request: ['amount' => '1', 'currency' => PATH_USD, 'recipient' => RECIPIENT, 'methodDetails' => ['chainId' => 42431]],
        payloadType: $real->payloadType,
        signature: $real->signature,
        source: $real->source,
        rawRequest: $real->rawRequest,
    );

    $result = tempoVerifier($rpc)->verifyTempo($tampered, tempoState());

    expect($result->succeeded)->toBeFalse()
        ->and($result->failureReason)->toContain('does not match')
        ->and($rpc->broadcasts)->toBeEmpty();
});
