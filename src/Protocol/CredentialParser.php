<?php

namespace Square1\Mpp\Protocol;

use Square1\Mpp\Exceptions\MalformedCredentialException;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Jcs;

/**
 * Parses an `Authorization: Payment …` header.
 *
 * A credential in the spec format is one base64url token. It decodes to a JSON
 * object with a `challenge` member and a `payload` member. The session extension
 * of this package, `Payment session="sess_…"`, is the one auth-param form that
 * the parser still accepts. An agent that holds a prepaid balance therefore does
 * not send a payment proof again on every spend.
 *
 * There are two failures, and they get two answers. A request with no `Payment`
 * header is not an error. The caller asks for the price, and receives a 402 with
 * a fresh challenge, so parse() returns null. A `Payment` header that is present
 * but is not a credential is a defect in the client. A new challenge would only
 * produce the same retry. parse() therefore throws
 * MalformedCredentialException, and the gate answers `malformed-credential`.
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

        // Decode WITHOUT the associative flag, so that a JSON object becomes a
        // stdClass and a JSON array becomes a PHP array. Associative decoding
        // converts `{}` and `[]` to the same empty array. A `challenge: []` that
        // breaks the schema would then pass. The difference matters here.
        $decoded = json_decode($json);
        if (! $decoded instanceof \stdClass) {
            throw new MalformedCredentialException('Payment credential does not decode to a JSON object.');
        }

        // Reject every float. A JSON number decodes to a PHP float when it has a
        // fraction or an exponent, or when its integer value is above
        // PHP_INT_MAX. A float loses numeric identity: 9007199254740993 and
        // 9007199254740992 are one double, and 1e400 is INF. Two different
        // credentials could then produce one fingerprint, and the second
        // credential would wrongly receive the paid replay of the first. An
        // integer within the range of PHP keeps its type, because json_encode
        // renders 123 and "123" differently. Only a float is therefore a
        // problem. The wire format encodes every amount as a string, so a
        // conformant credential carries no float.
        if ($this->hasFloat($decoded)) {
            throw new MalformedCredentialException(
                'Payment credential contains a non-integer or out-of-range number; encode numeric values as strings.'
            );
        }

        $challenge = $decoded->challenge ?? null;
        $payload = $decoded->payload ?? null;

        // The core schema types both challenge and payload as JSON objects. A
        // JSON array is not an object, and the parser rejects it. This includes
        // the empty array `[]`.
        if (! $challenge instanceof \stdClass || ! $payload instanceof \stdClass) {
            throw new MalformedCredentialException('Payment credential challenge and payload must be JSON objects.');
        }

        // `source` is an optional string. A source that is present and is not a
        // string is malformed.
        if (property_exists($decoded, 'source') && ! is_string($decoded->source)) {
            throw new MalformedCredentialException('Payment credential source must be a string.');
        }

        return new Credential(
            challenge: $this->toArray($challenge),
            payload: $this->toArray($payload),
            source: $decoded->source ?? null,
            // Canonicalize from the TYPED graph, before the parser flattens it to
            // arrays. JCS sorts the keys of an object, so a credential with
            // reordered keys still matches. JCS also keeps a stdClass distinct from
            // an array, so a nested {} never collides with a nested []. This value
            // is the source of the replay fingerprint.
            canonical: Jcs::encode((object) [
                'challenge' => $challenge,
                'payload' => $payload,
                'source' => $decoded->source ?? null,
            ]),
        );
    }

    /**
     * Converts a decoded JSON object graph to an associative array for the
     * Credential.
     *
     * The rest of the package reads the credential as arrays.
     *
     * @return array<string, mixed>
     */
    private function toArray(\stdClass $object): array
    {
        return (array) json_decode((string) json_encode($object), associative: true);
    }

    /**
     * Reports whether the decoded structure holds a float.
     *
     * A JSON number decodes to a float when it carries a fraction or an
     * exponent, or when its integer literal is outside the range of PHP. The
     * method recurses into both objects and arrays.
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
