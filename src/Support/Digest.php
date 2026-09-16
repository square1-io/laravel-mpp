<?php

namespace Square1\Mpp\Support;

use Illuminate\Http\Request;

/**
 * The RFC 9530 Content-Digest value over a request body.
 *
 * The spec asks a server to bind the body digest into the challenge, in slot 6
 * of the seven-slot HMAC, whenever the challenged request has a body. A
 * challenge that a client earned for one body then cannot authorize a paid retry
 * that carries a different body. The same helper runs on both sides of that
 * comparison: once at mint time, and once at settle time.
 *
 * The class emits only `sha-256`. RFC 9530 registers other algorithms, but this
 * value is internal to the server. It travels through the HMAC of this package,
 * and never through the parser of a client. One algorithm therefore keeps the
 * mint and the verification byte-comparable.
 */
final class Digest
{
    /**
     * Returns the Content-Digest value for the body of this request, or null when
     * the request has no body to digest.
     *
     * A body of zero length counts as no body. The alternative is a digest of the
     * empty string, which would bind every GET request to one constant value and
     * would add nothing.
     */
    public static function forRequest(Request $request): ?string
    {
        $body = $request->getContent();

        if (! is_string($body) || $body === '') {
            return null;
        }

        return 'sha-256=:'.base64_encode(hash('sha256', $body, binary: true)).':';
    }
}
