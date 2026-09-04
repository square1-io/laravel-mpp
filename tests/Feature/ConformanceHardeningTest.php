<?php

use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Tests\Fakes\FakeVerifier;

beforeEach(fn () => FakeVerifier::reset());

// ── credential canonicalization: reordered JSON keys still replay ────────────

it('replays a settled response for the same credential with reordered JSON keys', function () {
    $challenge = getChallenge($this, '/clip');

    // Settle with the canonical credential.
    payWithSpt($this, $challenge, '/clip')->assertOk()->assertSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);

    // The SAME logical credential, re-encoded with reordered object members
    // (payload before challenge, inner challenge keys reversed). JSON object
    // member order is not significant (RFC 8259 §4), so a retry must replay the
    // recorded 200 rather than settle a second time.
    $reordered = 'Payment '.Base64Url::encode((string) json_encode([
        'payload' => ['spt' => 'spt_test'],
        'challenge' => array_reverse($challenge, preserve_keys: true),
    ]));

    $this->withHeaders(['Authorization' => $reordered])->get('/clip')
        ->assertOk()
        ->assertSee('CLIP');

    // No second settlement — the reordered credential matched the fingerprint.
    expect(FakeVerifier::$calls)->toBe(1);
});

it('does not replay a settled response for a credential differing only by nested {} vs []', function () {
    // Two credentials whose only difference is a nested empty object vs empty
    // array in a custom payload field. They are distinct credentials and must
    // fingerprint differently, so the second must NOT receive the first's replay.
    $challenge = getChallenge($this, '/clip');

    $credentialWith = fn (mixed $metadata) => 'Payment '.Base64Url::encode((string) json_encode([
        'challenge' => $challenge,
        'payload' => ['spt' => 'spt_test', 'metadata' => $metadata],
    ]));

    // Credential A: metadata is an empty object. It settles.
    $this->withHeaders(['Authorization' => $credentialWith((object) [])])->get('/clip')
        ->assertOk()
        ->assertSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);

    // Credential B: metadata is an empty array. Same challenge id (now burned),
    // but a different credential, so it must not replay A's 200.
    $this->withHeaders(['Authorization' => $credentialWith([])])->get('/clip')
        ->assertStatus(402)
        ->assertDontSee('CLIP');

    // B neither replayed A's response nor re-settled the burned challenge.
    expect(FakeVerifier::$calls)->toBe(1);
});

// ── #1 route-identity binding beats a shared scope ──────────────────────────

it('does not settle a shared-scope challenge on a different-priced route', function () {
    // /shared/cheap (0.50) and /shared/pricey (5.00) share scope=shared. An
    // unspent cheap challenge must not be payable against the pricey route.
    $challenge = getChallenge($this, '/shared/cheap');

    payWithSpt($this, $challenge, '/shared/pricey')
        ->assertStatus(402)
        ->assertDontSee('PRICEY');

    expect(FakeVerifier::$calls)->toBe(0);
});

it('does not replay a settled shared-scope receipt on a different route', function () {
    $challenge = getChallenge($this, '/shared/cheap');
    payWithSpt($this, $challenge, '/shared/cheap')->assertOk();

    // The settled credential, replayed against the same-scope pricey route, must
    // not serve it — replay is bound to the route resource, not the scope.
    payWithSpt($this, $challenge, '/shared/pricey')
        ->assertStatus(402)
        ->assertDontSee('PRICEY');

    expect(FakeVerifier::$calls)->toBe(1);
});

// ── #2 request-body digest binding (RFC 9530) ───────────────────────────────

it('rejects a paid retry whose body differs from the challenged body', function () {
    $challenge402 = $this->call('POST', '/body', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"v":"a"}');
    $challenge402->assertStatus(402);
    $challenge = challengesFrom($challenge402)[0];

    $credential = paymentCredential($challenge, ['spt' => 'spt_test']);
    $pay = fn (string $json) => $this->call(
        'POST', '/body', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $credential],
        $json,
    );

    // A swapped body does not match the challenge's digest and must not settle.
    $pay('{"v":"b"}')->assertStatus(402);
    expect(FakeVerifier::$calls)->toBe(0);

    // The original body settles normally.
    $pay('{"v":"a"}')->assertOk()->assertSee('BODY');
    expect(FakeVerifier::$calls)->toBe(1);
});

// ── #4 typed protocol error problems ────────────────────────────────────────

it('returns a typed malformed-credential problem for an unparseable credential', function () {
    $this->withHeaders(['Authorization' => 'Payment @@not-base64@@'])
        ->get('/clip')
        ->assertStatus(402)
        ->assertJsonPath('type', 'https://paymentauth.org/problems/malformed-credential');

    expect(FakeVerifier::$calls)->toBe(0);
});

it('returns a typed invalid-challenge problem for an unknown challenge', function () {
    payWithSpt($this, ['id' => 'chal_nope'], '/clip')
        ->assertStatus(402)
        ->assertJsonPath('type', 'https://paymentauth.org/problems/invalid-challenge');
});

it('returns a typed verification-failed problem when settlement is declined', function () {
    FakeVerifier::$succeed = false;

    payWithSpt($this, getChallenge($this, '/clip'), '/clip')
        ->assertStatus(402)
        ->assertJsonPath('type', 'https://paymentauth.org/problems/verification-failed');
});

// ── concrete request-target binding (path params + query) ───────────────────

it('does not settle a challenge on a different concrete path under one pattern', function () {
    // /item/{tier} is one route pattern; /item/cheap and /item/expensive share
    // the scope. A challenge for one concrete path must not settle the other.
    $challenge = getChallenge($this, '/item/cheap');

    payWithSpt($this, $challenge, '/item/expensive')
        ->assertStatus(402)
        ->assertDontSee('ITEM expensive');

    expect(FakeVerifier::$calls)->toBe(0);
});

it('does not settle a challenge for a different query string', function () {
    $challenge = getChallenge($this, '/q?item=cheap');

    payWithSpt($this, $challenge, '/q?item=expensive')
        ->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(0);
});

// ── replay is gated by a credential fingerprint, not the challenge id ────────

it('does not replay a settled response for a different credential', function () {
    $challenge = getChallenge($this, '/clip');
    payWithSpt($this, $challenge, '/clip', 'spt_original')->assertOk();

    // Same (now burned) challenge id, different proof: the fingerprint differs,
    // so the paid response is not replayed to a different credential.
    payWithSpt($this, $challenge, '/clip', 'spt_different')
        ->assertStatus(402)
        ->assertDontSee('CLIP');

    expect(FakeVerifier::$calls)->toBe(1);
});

it('does not replay a settled response for a changed body', function () {
    $get = $this->call('POST', '/body', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"v":"a"}');
    $challenge = challengesFrom($get)[0];
    $credential = paymentCredential($challenge, ['spt' => 'spt_test']);
    $pay = fn (string $json) => $this->call(
        'POST', '/body', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $credential],
        $json,
    );

    $pay('{"v":"a"}')->assertOk();
    // Same credential, changed body: the fingerprint differs, so the replay does
    // not return the cached original.
    $pay('{"v":"b"}')->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(1);
});

// ── response replay completeness ────────────────────────────────────────────

it('replays every response header, not just content type', function () {
    $challenge = getChallenge($this, '/hdr');
    payWithSpt($this, $challenge, '/hdr')->assertOk();

    $replay = payWithSpt($this, $challenge, '/hdr')->assertOk();

    expect($replay->headers->get('X-Custom'))->toBe('xyz')
        ->and($replay->headers->get('Location'))->toBe('/elsewhere');
});

it('does not snapshot a streamed response and fails safe on replay', function () {
    $challenge = getChallenge($this, '/stream');
    payWithSpt($this, $challenge, '/stream')->assertOk();

    // The stream was not snapshotted, so the burned-challenge retry takes a fresh
    // 402 rather than replaying an empty body.
    payWithSpt($this, $challenge, '/stream')->assertStatus(402);

    expect(FakeVerifier::$calls)->toBe(1);
});

// ── #5 echoed-challenge equality ────────────────────────────────────────────

it('rejects a credential that echoes an altered challenge field', function () {
    $challenge = getChallenge($this, '/clip');
    $challenge['method'] = 'tempo'; // tamper the echoed method; the id stays stripe's

    payWithSpt($this, $challenge, '/clip')
        ->assertStatus(402)
        ->assertJsonPath('type', 'https://paymentauth.org/problems/malformed-credential');

    expect(FakeVerifier::$calls)->toBe(0);
});

it('rejects a credential that omits echoed challenge fields', function () {
    $challenge = getChallenge($this, '/clip');

    // A credential carrying only the id — no echoed realm/method/request/opaque.
    payWithSpt($this, ['id' => $challenge['id']], '/clip')
        ->assertStatus(402)
        ->assertJsonPath('type', 'https://paymentauth.org/problems/malformed-credential');

    expect(FakeVerifier::$calls)->toBe(0);
});
