<?php

namespace Square1\Mpp\Protocol;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * The record of one settled challenge.
 *
 * The package keeps the record so that a retry replays the original outcome,
 * instead of running the protected action again.
 *
 * To replay the stored response, and not to call the action again, is what makes
 * a retry after a lost response idempotent. The action runs exactly once, so its
 * side effects, such as a POST or a dispatched job, never repeat.
 *
 * `fingerprint` is a hash that no one can reverse. It covers the proof of the
 * successful credential, the digest of the request body, and the concrete
 * request target. A replay needs a match. A settled receipt is therefore not a
 * bearer token. The challenge id alone cannot retrieve the response, and that id
 * is not secret. Only the same payer that repeats the same request can retrieve
 * it.
 *
 * The response snapshot is the full buffered response: the status, the body,
 * every header, and the cookies. The gate does not snapshot a streamed response,
 * a binary response, or a response over the size limit, because it cannot replay
 * them correctly. The gate records nothing for such a response, so a retry takes
 * a fresh challenge. The record holds the session id and not the session, so
 * that the package reads `remaining` from the live session at replay.
 *
 * @param  array<string, list<string>>  $headers
 * @param  list<Cookie>  $cookies
 */
final class SettlementRecord
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly ?string $sessionId,
        public readonly int $status,
        public readonly string $content,
        public readonly array $headers,
        public readonly array $cookies,
    ) {}
}
