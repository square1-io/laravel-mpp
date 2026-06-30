<?php

use Square1\Mpp\Protocol\Tempo\MppxCodec;
use Square1\Mpp\Protocol\Tempo\TempoChallengeFactory;
use Square1\Mpp\Protocol\Tempo\TempoChallengeStore;
use Square1\Mpp\Settlement\SettlementChecker;
use Square1\Mpp\Settlement\SettlementOutcome;
use Square1\Mpp\Settlement\SettlementResult;
use Square1\Mpp\Settlement\TempoVerifier;

/**
 * End-to-end gate behaviour for the tempo (mppx-dialect) rail: the 402 byte
 * shape, the challenge binding/single-use, and the receipt emission. The on-chain
 * settlement itself is unit-tested against the real captured transaction in
 * TempoVerifierTest; here we drive the gate with a deterministic verifier so the
 * store/lock/burn/receipt plumbing is exercised without a chain.
 */

/**
 * Build an mppx credential for an issued challenge id (a transaction payload).
 * The signed-tx bytes are validated by the verifier, which is faked here.
 */
function tempoCredentialHeader(string $challengeId, MppxCodec $codec): string
{
    $wire = [
        'challenge' => [
            'expires' => '2099-01-01T00:00:00.000Z',
            'id' => $challengeId,
            'intent' => 'charge',
            'method' => 'tempo',
            'realm' => 'localhost',
            'request' => $codec->base64UrlEncode($codec->canonicalize([
                'amount' => '10000',
                'currency' => '0x20c0000000000000000000000000000000000000',
                'methodDetails' => ['chainId' => 42431],
                'recipient' => '0x0dcd39a3f85aa288c1b2825bc41eb7e9bb2abf70',
            ])),
        ],
        'payload' => ['signature' => '0x76aabbcc', 'type' => 'transaction'],
        'source' => 'did:pkh:eip155:42431:0xcccd947aa5ef6248febd3aaa41c07d8b0e9b3fe1',
    ];

    return 'Payment '.$codec->base64UrlEncode(json_encode($wire));
}

/** Bind a deterministic tempo verifier that succeeds (or fails) without a chain. */
function bindFakeTempoVerifier(bool $succeed = true, string $ref = '0xfeed'): void
{
    app()->bind(TempoVerifier::class, fn () => new class($succeed, $ref) extends TempoVerifier
    {
        public function __construct(private bool $ok, private string $ref)
        {
            parent::__construct(
                new class implements SettlementChecker
                {
                    public function settle(string $s, string $t, string $r, string $a, array $c): SettlementOutcome
                    {
                        return SettlementOutcome::confirmed('0x', 0, '0x', '0x', 1);
                    }
                },
                [],
            );
        }

        public function verifyTempo($credential, $state): SettlementResult
        {
            return $this->ok
                ? SettlementResult::settled($this->ref, (int) $state->amount, $state->token)
                : SettlementResult::failure('declined by fake');
        }
    });
}

it('emits an mppx-dialect 402 (Payment header + plain problem+json, no accepts[])', function () {
    $response = $this->get('/tempo');

    $response->assertStatus(402)
        ->assertHeader('Content-Type', 'application/problem+json');

    $www = $response->headers->get('WWW-Authenticate');
    expect($www)->toStartWith('Payment id="')
        ->and($www)->toContain('method="tempo"')
        ->and($www)->toContain('intent="charge"')
        ->and($www)->toContain('request="');

    $body = $response->json();
    expect($body['type'])->toBe('https://paymentauth.org/problems/payment-required')
        ->and($body['status'])->toBe(402)
        ->and($body)->toHaveKey('challengeId')
        ->and($body)->not->toHaveKey('accepts'); // single-dialect: no native accepts[]

    // The request blob decodes to the documented mppx request shape.
    preg_match('/request="([^"]+)"/', $www, $m);
    $request = json_decode((new MppxCodec)->base64UrlDecode($m[1]), true);
    expect($request)->toBe([
        'amount' => '10000',
        'currency' => '0x20c0000000000000000000000000000000000000',
        'methodDetails' => ['chainId' => 42431],
        'recipient' => '0x0dcd39a3f85aa288c1b2825bc41eb7e9bb2abf70',
    ]);
});

it('settles a paid retry and emits an mppx Payment-Receipt', function () {
    bindFakeTempoVerifier(true, '0xdeadbeefcafe');
    $codec = app(MppxCodec::class);

    // Issue a challenge (so it is in the store) and read its id.
    $www = $this->get('/tempo')->headers->get('WWW-Authenticate');
    preg_match('/id="([^"]+)"/', $www, $m);
    $id = $m[1];

    $response = $this->withHeaders([
        'Authorization' => tempoCredentialHeader($id, $codec),
    ])->get('/tempo');

    $response->assertOk()->assertSee('TEMPO');

    $receipt = $response->headers->get('Payment-Receipt');
    expect($receipt)->not->toBeNull();
    $decoded = json_decode($codec->base64UrlDecode($receipt), true);
    expect($decoded['method'])->toBe('tempo')
        ->and($decoded['status'])->toBe('success')
        ->and($decoded['reference'])->toBe('0xdeadbeefcafe');
});

it('burns the challenge after settling (single-use)', function () {
    bindFakeTempoVerifier(true);
    $codec = app(MppxCodec::class);

    $www = $this->get('/tempo')->headers->get('WWW-Authenticate');
    preg_match('/id="([^"]+)"/', $www, $m);
    $id = $m[1];

    expect(app(TempoChallengeStore::class)->exists($id))->toBeTrue();

    $this->withHeaders(['Authorization' => tempoCredentialHeader($id, $codec)])->get('/tempo')->assertOk();

    // Burned: a replay of the same challenge is re-challenged with a fresh 402.
    expect(app(TempoChallengeStore::class)->exists($id))->toBeFalse();
    $this->withHeaders(['Authorization' => tempoCredentialHeader($id, $codec)])->get('/tempo')->assertStatus(402);
});

it('re-challenges an unknown challenge id (fails closed)', function () {
    bindFakeTempoVerifier(true);
    $codec = app(MppxCodec::class);

    $this->withHeaders([
        'Authorization' => tempoCredentialHeader('not-a-real-challenge', $codec),
    ])->get('/tempo')->assertStatus(402);
});

it('re-challenges when the verifier declines (fails closed)', function () {
    bindFakeTempoVerifier(false);
    $codec = app(MppxCodec::class);

    $www = $this->get('/tempo')->headers->get('WWW-Authenticate');
    preg_match('/id="([^"]+)"/', $www, $m);

    $this->withHeaders([
        'Authorization' => tempoCredentialHeader($m[1], $codec),
    ])->get('/tempo')->assertStatus(402);
});

it('mints a verifiable, store-backed challenge id', function () {
    $store = app(TempoChallengeStore::class);
    $factory = app(TempoChallengeFactory::class);

    $www = $this->get('/tempo')->headers->get('WWW-Authenticate');
    preg_match('/id="([^"]+)"/', $www, $m);

    $state = $store->find($m[1]);
    expect($state)->not->toBeNull()
        ->and($state->amount)->toBe('10000')
        ->and($factory->verifyId($state))->toBeTrue(); // stateless HMAC binding holds
});
