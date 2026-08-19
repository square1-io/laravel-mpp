<?php

namespace Square1\Mpp\Protocol;

/**
 * A parsed `Authorization: Payment` credential.
 *
 * Spec form: `Payment <base64url>` decoding to
 * `{challenge: {id, …echoed params}, payload: {…rail proof}, source?}`.
 * The stored server-side challenge stays authoritative — the echo is matched
 * by id and never trusted for terms.
 *
 * The package additionally accepts its prepaid-session extension,
 * `Payment session="sess_…"`, issued after a metered (grants > 1) settlement.
 *
 * `spt()`/`proof()` read the rail proof out of the payload so verifiers stay
 * rail-neutral: `spt` is the Stripe payload key, `signature`/`hash` are
 * tempo's, and `proof` is the generic fallback for custom rails.
 */
class Credential
{
    /**
     * @param  array<string, mixed>  $challenge  the challenge parameters echoed by the client
     * @param  array<string, mixed>  $payload  rail-specific settlement proof
     * @param  string|null  $canonical  a type-aware canonical encoding of the
     *                                  originally decoded credential ({challenge, payload, source}), computed by
     *                                  the parser from the typed JSON graph. The replay fingerprint is taken
     *                                  over this, because the array forms above cannot tell a nested `{}` from a
     *                                  `[]`. Null for a session credential, which never settles.
     */
    public function __construct(
        public readonly array $challenge = [],
        public readonly array $payload = [],
        public readonly ?string $source = null,
        public readonly ?string $session = null,
        public readonly ?string $canonical = null,
    ) {}

    public function challengeId(): ?string
    {
        $id = $this->challenge['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function isSession(): bool
    {
        return $this->session !== null && $this->session !== '';
    }

    public function isSettlementProof(): bool
    {
        return $this->challengeId() !== null && $this->payload !== [];
    }

    /**
     * Whether this credential faithfully echoes the given challenge. Every
     * parameter the challenge minted — realm, method, intent, request, expires,
     * and (when present) digest and opaque — must be echoed back and match. A
     * missing or altered field fails, so a bare `{id}` credential or a tampered
     * echo is rejected. The stored challenge stays authoritative for dispatch;
     * this enforces that the credential names the challenge it actually presents.
     */
    public function echoes(Challenge $challenge): bool
    {
        $expected = [
            'realm' => $challenge->realm,
            'method' => $challenge->method,
            'intent' => $challenge->intent,
            'request' => $challenge->requestB64(),
            'expires' => $challenge->expiresParam(),
        ];

        if ($challenge->digestParam() !== '') {
            $expected['digest'] = $challenge->digestParam();
        }

        if ($challenge->opaqueB64() !== '') {
            $expected['opaque'] = $challenge->opaqueB64();
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $this->challenge) || (string) $this->challenge[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function spt(): ?string
    {
        $spt = $this->payload['spt'] ?? null;

        return is_string($spt) && $spt !== '' ? $spt : null;
    }

    /**
     * A generic settlement reference for rails that present one opaque proof.
     */
    public function proof(): ?string
    {
        foreach (['proof', 'spt', 'hash'] as $key) {
            $value = $this->payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
