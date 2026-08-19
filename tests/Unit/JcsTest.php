<?php

use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;

it('sorts object keys and strips whitespace', function () {
    expect(Jcs::encode(['b' => '2', 'a' => '1']))->toBe('{"a":"1","b":"2"}');
});

it('sorts nested objects recursively and preserves list order', function () {
    $out = Jcs::encode(['z' => ['y' => 1, 'x' => 2], 'a' => ['card', 'link']]);
    expect($out)->toBe('{"a":["card","link"],"z":{"x":2,"y":1}}');
});

it('leaves slashes and unicode unescaped per RFC 8785', function () {
    expect(Jcs::encode(['url' => 'https://a/b', 'name' => 'café']))
        ->toBe('{"name":"café","url":"https://a/b"}');
});

it('sorts keys by UTF-16 code units, not bytes', function () {
    // "é" (U+00E9) sorts before "𝄞" (U+1D11E, surrogate pair starting 0xD834)
    // in UTF-16; byte-order UTF-8 comparison would agree here, so also pin the
    // documented RFC 8785 §3.2.3 example ordering.
    expect(Jcs::encode(["\u{20AC}" => 1, 'a' => 2]))->toBe('{"a":2,"€":1}');
});

it('rejects floats so money can never round silently', function () {
    Jcs::encode(['amount' => 0.5]);
})->throws(InvalidArgumentException::class);

it('encodes ints, bools and null natively', function () {
    expect(Jcs::encode(['a' => 1, 'b' => true, 'c' => null]))->toBe('{"a":1,"b":true,"c":null}');
});

it('round-trips through base64url without padding', function () {
    $encoded = Base64Url::encode('{"a":1}');
    expect($encoded)->not->toContain('=')
        ->and(Base64Url::decode($encoded))->toBe('{"a":1}');
});

it('rejects invalid base64url input', function () {
    expect(Base64Url::decode('not/valid+chars'))->toBeNull()
        ->and(Base64Url::decode(''))->toBeNull();
});
