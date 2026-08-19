<?php

use Illuminate\Http\Request;
use Square1\Mpp\Support\Digest;

function requestWithBody(?string $body, string $method = 'POST'): Request
{
    return Request::create('/paid', $method, [], [], [], [], $body);
}

it('matches the RFC 9530 example vector', function () {
    // The digest of `{"hello": "world"}` as printed in RFC 9530 Section 2. If
    // this drifts, every challenge minted before the drift stops verifying.
    $digest = Digest::forRequest(requestWithBody('{"hello": "world"}'));

    expect($digest)->toBe('sha-256=:X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=:');
});

it('wraps standard base64 of the raw hash in the sha-256 sf-binary form', function () {
    $body = 'hello world';
    $digest = Digest::forRequest(requestWithBody($body));

    expect($digest)->toBe('sha-256=:'.base64_encode(hash('sha256', $body, true)).':')
        ->and($digest)->toStartWith('sha-256=:')
        ->and($digest)->toEndWith(':');
});

it('returns null when there is no body', function (string $method) {
    expect(Digest::forRequest(requestWithBody(null, $method)))->toBeNull()
        ->and(Digest::forRequest(requestWithBody('', $method)))->toBeNull();
})->with(['GET', 'POST']);

it('distinguishes bodies that differ only in whitespace', function () {
    // No canonicalization: the digest covers the bytes as sent, so a proxy that
    // reserializes the body invalidates the challenge rather than passing.
    expect(Digest::forRequest(requestWithBody('{"a":1}')))
        ->not->toBe(Digest::forRequest(requestWithBody('{"a": 1}')));
});

it('does not consume the body it reads', function () {
    $request = requestWithBody('{"a":1}');

    expect(Digest::forRequest($request))->toBe(Digest::forRequest($request))
        ->and($request->getContent())->toBe('{"a":1}');
});
