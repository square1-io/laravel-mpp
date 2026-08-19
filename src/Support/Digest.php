<?php

namespace Square1\Mpp\Support;

use Illuminate\Http\Request;

/**
 * RFC 9530 Content-Digest values over a request body.
 *
 * The spec asks servers to bind the body digest into the challenge (slot 6 of
 * the seven-slot HMAC) whenever the challenged request has a body, so a
 * challenge earned for one body cannot authorize a paid retry carrying a
 * different one. The same helper runs on both sides of that comparison: once at
 * mint time and once at settle time.
 *
 * Only `sha-256` is emitted — RFC 9530 registers others, but the value is
 * server-internal (it round-trips through our own HMAC, never a client's
 * parser), so a single algorithm keeps mint and verify byte-comparable.
 */
final class Digest
{
    /**
     * The Content-Digest value for this request's body, or null when there is
     * no body to digest. A zero-length body is "no body": the alternative is a
     * digest of the empty string, which would bind every GET to a constant and
     * buy nothing.
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
