<?php

namespace Square1\Mpp\Protocol;

/**
 * A parsed `Authorization: Payment` credential.
 *
 * The spec form is `Payment <base64url>`. It decodes to
 * `{challenge: {id, …echoed params}, payload: {…rail proof}, source?}`. The
 * challenge that the server stored stays authoritative. The package matches the
 * echoed copy by id, and never trusts it for the terms.
 *
 * The package also accepts its own prepaid-session extension,
 * `Payment session="sess_…"`. The server issues that credential after it settles
 * a metered payment, which is a payment with grants > 1.
 *
 * `spt()` and `proof()` read the rail proof from the payload, so that a verifier
 * stays independent of the rail. `spt` is the payload key of Stripe.
 * `signature` and `hash` are the keys of tempo. `proof` is the general fallback
 * for a custom rail.
 */
class Credential
{
    /**
     * @param  array<string, mixed>  $challenge  the challenge parameters that the client echoed
     * @param  array<string, mixed>  $payload  the settlement proof of the rail
     * @param  string|null  $canonical  A canonical encoding of the decoded credential,
     *                                  {challenge, payload, source}, that keeps the JSON types. The
     *                                  parser computes it from the typed JSON graph. The replay
     *                                  fingerprint covers this value, because the array forms above
     *                                  cannot separate a nested `{}` from a `[]`. It is null for a
     *                                  session credential, which never settles.
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
     * Reports whether this credential echoes the given challenge correctly.
     *
     * The credential must echo every parameter that the server minted, and each
     * one must match. Those parameters are the realm, the method, the intent, the
     * request and the expiry, and, when the challenge carries them, the digest
     * and the opaque value. A field that is absent or altered fails the check.
     * The method therefore rejects a bare `{id}` credential and an altered echo.
     *
     * The stored challenge stays authoritative for dispatch. This check enforces
     * that the credential names the challenge that it presents.
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
     * Returns a general settlement reference, for a rail that presents one
     * opaque proof.
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
