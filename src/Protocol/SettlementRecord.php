<?php

namespace Square1\Mpp\Protocol;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * The record of one settled challenge, kept so a retry replays the exact
 * original outcome instead of re-running the protected action.
 *
 * Replaying the stored response — rather than calling the action again — is what
 * makes a lost-response retry idempotent: the action runs exactly once, so its
 * side effects (a POST, a job dispatch) never repeat.
 *
 * `fingerprint` is a non-reversible hash of the successful credential's proof,
 * the request-body digest, and the concrete request target. Replay requires it
 * to match, so a settled receipt is not a bearer token: the challenge id alone
 * (which is not secret) cannot retrieve the response — only the same payer
 * repeating the same request can.
 *
 * The response snapshot is the full buffered response: status, body, every
 * header, and cookies. Streamed, binary, and over-limit responses are not
 * snapshotted at all (they cannot be replayed faithfully); the gate records
 * nothing for them, so their retries take a fresh challenge. The session id, not
 * the session, is stored so `remaining` is re-read live at replay.
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
