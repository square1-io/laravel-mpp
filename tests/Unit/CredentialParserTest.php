<?php

use Square1\Mpp\Exceptions\MalformedCredentialException;
use Square1\Mpp\Protocol\CredentialParser;
use Square1\Mpp\Support\Base64Url;

beforeEach(function () {
    $this->parser = new CredentialParser;
});

it('parses a spec base64url credential', function () {
    $header = paymentCredential(['id' => 'chal_1', 'method' => 'stripe'], ['spt' => 'spt_1']);
    $c = $this->parser->parse($header);

    expect($c)->not->toBeNull()
        ->and($c->challengeId())->toBe('chal_1')
        ->and($c->spt())->toBe('spt_1')
        ->and($c->isSettlementProof())->toBeTrue()
        ->and($c->isSession())->toBeFalse();
});

it('parses a tempo credential with source and typed payload', function () {
    $header = paymentCredential(
        ['id' => 'chal_2', 'method' => 'tempo'],
        ['type' => 'transaction', 'signature' => '0x76f9abc'],
        source: 'did:pkh:eip155:4217:0x1234',
    );
    $c = $this->parser->parse($header);

    expect($c->challengeId())->toBe('chal_2')
        ->and($c->payload['type'])->toBe('transaction')
        ->and($c->payload['signature'])->toBe('0x76f9abc')
        ->and($c->source)->toBe('did:pkh:eip155:4217:0x1234')
        ->and($c->spt())->toBeNull();
});

it('parses the session extension', function () {
    $c = $this->parser->parse('Payment session="sess_1"');

    expect($c->session)->toBe('sess_1')
        ->and($c->isSession())->toBeTrue()
        ->and($c->isSettlementProof())->toBeFalse();
});

it('returns null for non-payment schemes and empty headers', function (?string $header) {
    // No credential offered is not an error: the caller is asking the price.
    expect($this->parser->parse($header))->toBeNull();
})->with(['Bearer x', 'Basic x', [null], ['']]);

it('throws on a credential missing challenge or payload', function (string $header) {
    expect(fn () => $this->parser->parse($header))->toThrow(MalformedCredentialException::class);
})->with([
    'missing payload' => fn () => 'Payment '.Base64Url::encode('{"challenge":{"id":"x"}}'),
    'missing challenge' => fn () => 'Payment '.Base64Url::encode('{"payload":{"spt":"s"}}'),
    'scalar members' => fn () => 'Payment '.Base64Url::encode('{"challenge":"x","payload":"y"}'),
    'not json' => fn () => 'Payment '.Base64Url::encode('plainly not json'),
    'json scalar' => fn () => 'Payment '.Base64Url::encode('"a string"'),
]);

it('throws on a present but undecodable token', function (string $header) {
    expect(fn () => $this->parser->parse($header))->toThrow(MalformedCredentialException::class);
})->with([
    'not base64url' => 'Payment !!!!not base64url!!!!',
    'padded base64' => 'Payment YWJj==',
    'bare scheme' => 'Payment',
    'scheme and space' => 'Payment ',
]);

it('throws on legacy auth-param credentials', function () {
    // The pre-v2 native dialect. Answering 402 would invite the identical
    // retry; malformed-credential tells the client to fix its serializer.
    expect(fn () => $this->parser->parse('Payment method="stripe", challengeId="chal_1", spt="spt_1"'))
        ->toThrow(MalformedCredentialException::class);
});

it('distinguishes an absent credential from a malformed one', function () {
    expect($this->parser->parse(null))->toBeNull()
        ->and(fn () => $this->parser->parse('Payment garbage!'))
        ->toThrow(MalformedCredentialException::class);
});

it('reads a generic proof out of the payload with spt and hash fallbacks', function () {
    $spt = $this->parser->parse(paymentCredential(['id' => 'c'], ['spt' => 'spt_1']));
    $hash = $this->parser->parse(paymentCredential(['id' => 'c'], ['type' => 'hash', 'hash' => '0xabc']));
    $proof = $this->parser->parse(paymentCredential(['id' => 'c'], ['proof' => 'ref_9', 'spt' => 'spt_1']));

    expect($spt->proof())->toBe('spt_1')
        ->and($hash->proof())->toBe('0xabc')
        ->and($proof->proof())->toBe('ref_9');
});

it('rejects a credential carrying a float or out-of-range number', function (string $json) {
    // Floats lose numeric identity (1e400 is INF; 9007199254740993 and ...992 are
    // one double; 9223372036854775808 overflows to a float), so two distinct
    // credentials could share a fingerprint. Reject before any charge.
    expect(fn () => $this->parser->parse('Payment '.Base64Url::encode($json)))
        ->toThrow(MalformedCredentialException::class, 'number');
})->with([
    'infinity' => '{"challenge":{"id":"c1"},"payload":{"spt":"x","n":1e400}}',
    'fractional' => '{"challenge":{"id":"c1"},"payload":{"spt":"x","n":1.5}}',
    'unsafe float' => '{"challenge":{"id":"c1"},"payload":{"spt":"x","n":9007199254740993.0}}',
    'integer above PHP_INT_MAX' => '{"challenge":{"id":"c1"},"payload":{"n":9223372036854775808}}',
]);

it('keeps an in-range integer distinct from its string form', function () {
    // A JSON number and the same digits as a JSON string are different credentials
    // and must not share a fingerprint. In-range integers keep their type.
    $num = $this->parser->parse('Payment '.Base64Url::encode('{"challenge":{"id":"c1"},"payload":{"n":123}}'));
    $str = $this->parser->parse('Payment '.Base64Url::encode('{"challenge":{"id":"c1"},"payload":{"n":"123"}}'));

    expect($num->payload['n'])->toBe(123)
        ->and($str->payload['n'])->toBe('123');
});

it('rejects a credential whose challenge or payload is a JSON array', function (string $json) {
    expect(fn () => $this->parser->parse('Payment '.Base64Url::encode($json)))
        ->toThrow(MalformedCredentialException::class, 'must be JSON objects');
})->with([
    'list challenge' => '{"challenge":["c1"],"payload":{"spt":"x"}}',
    'list payload' => '{"challenge":{"id":"c1"},"payload":["x"]}',
    // An empty array is a JSON array, not an object, so it must be rejected too
    // (associative decoding would otherwise collapse [] and {} to the same value).
    'empty-array challenge' => '{"challenge":[],"payload":{"spt":"x"}}',
    'empty-array payload' => '{"challenge":{"id":"c1"},"payload":[]}',
]);

it('rejects a credential with a non-string source', function () {
    $json = '{"challenge":{"id":"c1"},"payload":{"spt":"x"},"source":123}';

    expect(fn () => $this->parser->parse('Payment '.Base64Url::encode($json)))
        ->toThrow(MalformedCredentialException::class, 'source must be a string');
});
