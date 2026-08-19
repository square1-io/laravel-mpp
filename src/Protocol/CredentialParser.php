<?php

namespace Square1\Mpp\Protocol;

use Square1\Mpp\Exceptions\MalformedCredentialException;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;

/**
 * Parses `Authorization: Payment …` headers.
 *
 * Spec credentials are a single base64url token decoding to a JSON object with
 * `challenge` and `payload` members. The package's session extension —
 * `Payment session="sess_…"` — is the one auth-param form still accepted, so
 * an agent holding a prepaid balance doesn't re-send a payment proof on every
 * spend.
 *
 * Two failures, two answers. No `Payment` header at all is not an error: the
 * caller is asking the price and gets a 402 with a fresh challenge, so parse()
 * returns null. A `Payment` header that is present but is not a credential is a
 * client bug — re-challenging it invites the identical retry — so parse()
 * throws MalformedCredentialException and the gate answers
 * `malformed-credential`.
 */
class CredentialParser
{
    /**
     * @return Credential|null null when no Payment credential was offered
     *
     * @throws MalformedCredentialException when one was offered but is unparseable
     */
    public function parse(?string $header): ?Credential
    {
        if (! is_string($header) || ! preg_match('/^\s*Payment\b\s*(.*)$/is', trim($header), $m)) {
            return null;
        }

        $token = trim($m[1]);

        if ($token === '') {
            throw new MalformedCredentialException('Payment credential is empty.');
        }

        if (preg_match('/^session\s*=\s*"?([\w:-]+)"?$/i', $token, $s)) {
            return new Credential(session: $s[1]);
        }

        $json = Base64Url::decode($token);
        if ($json === null) {
            throw new MalformedCredentialException('Payment credential is not a base64url token.');
        }

        // Decode WITHOUT associative, so a JSON object is a stdClass and a JSON
        // array is a PHP array. Associative decoding collapses `{}` and `[]` to
        // the same empty array, which would let a schema-violating `challenge: []`
        // pass. The distinction is load-bearing here.
        $decoded = json_decode($json);
        if (! $decoded instanceof \stdClass) {
            throw new MalformedCredentialException('Payment credential does not decode to a JSON object.');
        }

        // Reject every float. A JSON number decodes to a PHP float when it has a
        // fraction or exponent, or when its integer value exceeds PHP_INT_MAX.
        // Floats lose numeric identity (9007199254740993 and ...992 are one
        // double, 1e400 is INF), so two distinct credentials could produce one
        // fingerprint and the second wrongly receive the first's paid replay.
        // Integers within PHP's range keep their type (json_encode renders 123
        // and "123" differently), so only floats are dangerous. The wire encodes
        // every amount as a string, so a conformant credential carries no floats.
        if ($this->hasFloat($decoded)) {
            throw new MalformedCredentialException(
                'Payment credential contains a non-integer or out-of-range number; encode numeric values as strings.'
            );
        }

        $challenge = $decoded->challenge ?? null;
        $payload = $decoded->payload ?? null;

        // The core schema types challenge and payload as JSON objects. A JSON
        // array (including the empty `[]`) is not an object and is rejected.
        if (! $challenge instanceof \stdClass || ! $payload instanceof \stdClass) {
            throw new MalformedCredentialException('Payment credential challenge and payload must be JSON objects.');
        }

        // `source` is an optional string. A present, non-string source is malformed.
        if (property_exists($decoded, 'source') && ! is_string($decoded->source)) {
            throw new MalformedCredentialException('Payment credential source must be a string.');
        }

        return new Credential(
            challenge: $this->toArray($challenge),
            payload: $this->toArray($payload),
            source: $decoded->source ?? null,
            // Canonicalize from the TYPED graph, before it is flattened to arrays.
            // JCS sorts object keys (so a reordered credential still matches) and
            // keeps stdClass distinct from array (so a nested {} never collides
            // with a nested []). This is the source of the replay fingerprint.
            canonical: Jcs::encode((object) [
                'challenge' => $challenge,
                'payload' => $payload,
                'source' => $decoded->source ?? null,
            ]),
        );
    }

    /**
     * Convert a decoded JSON object graph to an associative array for the
     * Credential, which the rest of the package consumes as arrays.
     *
     * @return array<string, mixed>
     */
    private function toArray(\stdClass $object): array
    {
        return (array) json_decode((string) json_encode($object), associative: true);
    }

    /**
     * Whether the decoded structure holds any float. A JSON number decodes to a
     * float when it carries a fraction or exponent, or when its integer literal
     * is out of PHP's range. Recurses both objects and arrays.
     *
     * @param  mixed  $value
     */
    private function hasFloat($value): bool
    {
        if (is_float($value)) {
            return true;
        }

        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasFloat($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
