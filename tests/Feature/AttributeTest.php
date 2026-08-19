<?php

use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Tests\Fakes\FakeVerifier;
use Square1\Mpp\Tests\TestCase;

/**
 * @return array{request: array<string, mixed>, opaque: array<string, mixed>}
 */
function attrChallengeData(TestCase $test, string $uri): array
{
    $challenge = getChallenge($test, $uri);

    return [
        'request' => (array) json_decode((string) Base64Url::decode($challenge['request'] ?? ''), true),
        'opaque' => (array) json_decode((string) Base64Url::decode($challenge['opaque'] ?? ''), true),
    ];
}

beforeEach(fn () => FakeVerifier::reset());

it('challenges an attributed action via the explicit mpp middleware', function () {
    $this->get('/attr/explicit')->assertStatus(402);

    $data = attrChallengeData($this, '/attr/explicit');

    expect($data['request']['amount'])->toBe('50')
        ->and($data['opaque'])->not->toHaveKey('grants'); // single-grant challenges omit it
});

it('settles an attributed action reached via the explicit mpp middleware', function () {
    payWithSpt($this, getChallenge($this, '/attr/explicit'), '/attr/explicit')->assertOk()->assertSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);
});

it('auto-enforces the attribute via the EnforcePaymentAttributes middleware', function () {
    $this->get('/attr/auto')->assertStatus(402);
    expect(attrChallengeData($this, '/attr/auto')['request']['amount'])->toBe('50');

    payWithSpt($this, getChallenge($this, '/attr/auto'), '/attr/auto')->assertOk()->assertSee('CLIP');
    expect(FakeVerifier::$calls)->toBe(1);
});

it('auto-enforces a metered attributed action with its grants and scope', function () {
    $this->get('/attr/report')->assertStatus(402);

    $data = attrChallengeData($this, '/attr/report');

    expect($data['request']['amount'])->toBe('500')
        ->and($data['opaque']['grants'])->toBe('10')
        ->and($data['opaque']['scope'])->toBe('report.basic');
});

it('passes through routes without the attribute', function () {
    $this->get('/attr/plain')->assertOk()->assertSee('FREE');
    expect(FakeVerifier::$calls)->toBe(0);
});
