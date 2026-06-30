<?php

use Carbon\CarbonImmutable;
use Square1\Mpp\Protocol\Tempo\MppxCodec;
use Square1\Mpp\Protocol\Tempo\TempoChallengeState;

/**
 * Asserts the mppx wire dialect byte-shape against the EXACT captured exchange
 * from a stock `npx mppx` client (the reference server emitted/consumed these).
 */
function codecState(string $recipient = '0x0dcd39A3F85aa288C1B2825bc41EB7e9BB2ABF70'): TempoChallengeState
{
    return new TempoChallengeState(
        id: 'tdCiAaAQPDdNOGahL56A343eGtfHlCVnbY99W_DMbZ8',
        realm: 'localhost',
        amount: '10000',
        token: '0x20c0000000000000000000000000000000000000',
        recipient: $recipient,
        chainId: 42431,
        expiresAt: CarbonImmutable::parse('2026-06-22T15:26:33.158Z'),
    );
}

it('encodes the request blob byte-for-byte against the captured 402', function () {
    // The captured reference 402 passed the recipient through with its original
    // (mixed) casing; our codec preserves the configured casing identically.
    $expected = 'eyJhbW91bnQiOiIxMDAwMCIsImN1cnJlbmN5IjoiMHgyMGMwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwIiwibWV0aG9kRGV0YWlscyI6eyJjaGFpbklkIjo0MjQzMX0sInJlY2lwaWVudCI6IjB4MGRjZDM5QTNGODVhYTI4OEMxQjI4MjViYzQxRUI3ZTlCQjJBQkY3MCJ9';

    expect((new MppxCodec)->encodeRequest(codecState()))->toBe($expected);
});

it('emits a Payment WWW-Authenticate header in mppx auth-param order', function () {
    $header = (new MppxCodec)->wwwAuthenticate(codecState());

    expect($header)->toStartWith('Payment id="tdCiAaAQPDdNOGahL56A343eGtfHlCVnbY99W_DMbZ8", realm="localhost", method="tempo", intent="charge", request="')
        ->and($header)->toContain('expires="2026-06-22T15:26:33.158Z"');

    // Auth-params in the exact reference order.
    preg_match_all('/(\w+)="/', $header, $m);
    expect($m[1])->toBe(['id', 'realm', 'method', 'intent', 'request', 'expires']);
});

it('emits a plain RFC 9457 problem+json body with no accepts[]', function () {
    $body = (new MppxCodec)->problemDocument(codecState());

    expect($body)->toBe([
        'type' => 'https://paymentauth.org/problems/payment-required',
        'title' => 'Payment Required',
        'status' => 402,
        'detail' => 'Payment is required.',
        'challengeId' => 'tdCiAaAQPDdNOGahL56A343eGtfHlCVnbY99W_DMbZ8',
    ])->and($body)->not->toHaveKey('accepts');
});

it('emits a Payment-Receipt header byte-for-byte against the capture', function () {
    $header = (new MppxCodec)->receiptHeader(
        '0xe6f620fd235aafbb18d570fc798bb0a556189025608e99e1d3b21ab49d511544',
        CarbonImmutable::parse('2026-06-22T15:21:34.889Z'),
    );

    $expected = 'eyJtZXRob2QiOiJ0ZW1wbyIsInN0YXR1cyI6InN1Y2Nlc3MiLCJ0aW1lc3RhbXAiOiIyMDI2LTA2LTIyVDE1OjIxOjM0Ljg4OVoiLCJyZWZlcmVuY2UiOiIweGU2ZjYyMGZkMjM1YWFmYmIxOGQ1NzBmYzc5OGJiMGE1NTYxODkwMjU2MDhlOTllMWQzYjIxYWI0OWQ1MTE1NDQifQ';

    expect($header)->toBe($expected);

    // And it round-trips back to the documented receipt JSON shape.
    $json = json_decode((new MppxCodec)->base64UrlDecode($header), true);
    expect($json)->toBe([
        'method' => 'tempo',
        'status' => 'success',
        'timestamp' => '2026-06-22T15:21:34.889Z',
        'reference' => '0xe6f620fd235aafbb18d570fc798bb0a556189025608e99e1d3b21ab49d511544',
    ]);
});

it('parses the captured credential', function () {
    $captured = 'Payment '.CAPTURED_CREDENTIAL;
    $credential = (new MppxCodec)->parseCredential($captured);

    expect($credential)->not->toBeNull()
        ->and($credential->challengeId)->toBe('tdCiAaAQPDdNOGahL56A343eGtfHlCVnbY99W_DMbZ8')
        ->and($credential->method)->toBe('tempo')
        ->and($credential->intent)->toBe('charge')
        ->and($credential->isTransaction())->toBeTrue()
        ->and($credential->request['amount'])->toBe('10000')
        ->and($credential->request['methodDetails']['chainId'])->toBe(42431);
});

it('does not mistake a native-dialect Payment credential for an mppx one', function () {
    $native = 'Payment method="stripe", challengeId="chal_x", sig="abc", spt="spt_1"';

    expect((new MppxCodec)->parseCredential($native))->toBeNull();
});

it('canonicalizes objects with recursively sorted keys and no whitespace', function () {
    $codec = new MppxCodec;

    expect($codec->canonicalize(['b' => 2, 'a' => 1]))->toBe('{"a":1,"b":2}')
        ->and($codec->canonicalize(['z' => [3, ['y' => 1, 'x' => 2]], 'a' => 'hello']))
        ->toBe('{"a":"hello","z":[3,{"x":2,"y":1}]}');
});
