<?php

namespace Square1\Mpp\Protocol\Tempo;

use Carbon\CarbonImmutable;

/**
 * The mppx wire dialect for the Tempo rail — separate from the package's native
 * attribute/`accepts[]` dialect, and matched byte-for-byte against the mppx
 * reference implementation so a stock `npx mppx … --network testnet` client
 * interoperates with our Laravel routes.
 *
 * Reference mapping (mppx `dist/`):
 *   - 402 header → `Challenge.serialize` (scheme "Payment"; quoted auth-params
 *     in order id, realm, method, intent, request; `request` is base64url of
 *     canonical JSON via `PaymentRequest.serialize`).  Challenge.js:158-182,
 *     PaymentRequest.js:77-79
 *   - 402 body → RFC 9457 problem+json `{type,title,status,detail,challengeId}`
 *     with type `https://paymentauth.org/problems/payment-required`.
 *   - credential → `Authorization: Payment <base64url-json>` of
 *     `{challenge:{expires,id,intent,method,realm,request}, payload:{signature,
 *     type}, source}`.  Credential.js:38-62,145-159
 *   - receipt → `Payment-Receipt: <base64url-json>` of
 *     `{method,status,timestamp,reference}`.  Receipt.js:80-82
 *
 * A single 402 offers exactly ONE dialect — tempo OR the native stripe dialect —
 * because their encodings differ (base64url request blob + plain problem+json
 * here, vs. signed `accepts[]` there). A tempo route emits this dialect; stripe
 * routes keep the native one.
 */
final class MppxCodec
{
    public const PROBLEM_TYPE = 'https://paymentauth.org/problems/payment-required';

    /**
     * Build the `WWW-Authenticate: Payment …` header for a tempo challenge.
     */
    public function wwwAuthenticate(TempoChallengeState $state): string
    {
        $params = [
            'id' => $state->id,
            'realm' => $state->realm,
            'method' => 'tempo',
            'intent' => $state->intent,
            'request' => $this->encodeRequest($state),
            'expires' => $this->expires($state->expiresAt),
        ];

        $parts = [];
        foreach ($params as $name => $value) {
            $parts[] = sprintf('%s="%s"', $name, $this->escapeQuoted((string) $value));
        }

        return 'Payment '.implode(', ', $parts);
    }

    /**
     * The RFC 9457 problem+json body for a tempo 402.
     *
     * @return array<string, mixed>
     */
    public function problemDocument(TempoChallengeState $state, ?string $detail = null): array
    {
        return [
            'type' => self::PROBLEM_TYPE,
            'title' => 'Payment Required',
            'status' => 402,
            'detail' => $detail ?? 'Payment is required.',
            'challengeId' => $state->id,
        ];
    }

    /**
     * base64url(no-pad) of the canonical mppx request JSON.
     */
    public function encodeRequest(TempoChallengeState $state): string
    {
        return $this->base64UrlEncode($this->canonicalize($state->toRequestArray()));
    }

    /**
     * Parse an `Authorization: Payment <base64url-json>` tempo credential.
     *
     * @return ParsedTempoCredential|null null when the header is absent or not a
     *                                    parseable mppx Payment credential
     */
    public function parseCredential(?string $header): ?ParsedTempoCredential
    {
        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        if (! preg_match('/^\s*Payment\s+(.+)$/is', trim($header), $m)) {
            return null;
        }

        $token = trim($m[1]);

        // The native dialect uses `Payment key="value", …`; the mppx dialect uses
        // a single base64url-json token. If it looks like auth-params, this is
        // not an mppx credential.
        if (str_contains($token, '=') && str_contains($token, '"')) {
            return null;
        }

        $json = $this->base64UrlDecode($token);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['challenge']) || ! isset($data['payload'])) {
            return null;
        }

        $challenge = $data['challenge'];
        $payload = $data['payload'];

        if (! is_array($challenge) || ! is_array($payload)) {
            return null;
        }

        $request = isset($challenge['request']) && is_string($challenge['request'])
            ? json_decode($this->base64UrlDecode($challenge['request']) ?? 'null', true)
            : null;

        return new ParsedTempoCredential(
            challengeId: (string) ($challenge['id'] ?? ''),
            realm: (string) ($challenge['realm'] ?? ''),
            method: (string) ($challenge['method'] ?? 'tempo'),
            intent: (string) ($challenge['intent'] ?? 'charge'),
            expires: isset($challenge['expires']) ? (string) $challenge['expires'] : null,
            request: is_array($request) ? $request : [],
            payloadType: (string) ($payload['type'] ?? ''),
            signature: (string) ($payload['signature'] ?? ''),
            source: isset($data['source']) ? (string) $data['source'] : null,
            rawRequest: isset($challenge['request']) ? (string) $challenge['request'] : null,
        );
    }

    /**
     * The `Payment-Receipt` header value (base64url-json).
     */
    public function receiptHeader(string $reference, ?CarbonImmutable $timestamp = null): string
    {
        $receipt = [
            'method' => 'tempo',
            'status' => 'success',
            'timestamp' => $this->expires($timestamp ?? CarbonImmutable::now()),
            'reference' => $reference,
        ];

        return $this->base64UrlEncode(json_encode($receipt, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Extract the payer EVM address from a `did:pkh:eip155:<chainId>:0x<addr>`.
     */
    public function payerFromSource(?string $source): ?string
    {
        if ($source === null || ! preg_match('/^did:pkh:eip155:(\d+):(0x[0-9a-fA-F]{40})$/', $source, $m)) {
            return null;
        }

        return strtolower($m[2]);
    }

    /**
     * Recursive canonical JSON: keys sorted by code unit, no whitespace, undefined
     * dropped — matches ox `Json.canonicalize`.
     */
    public function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);

            if ($isList) {
                return '['.implode(',', array_map(fn ($v) => $this->canonicalize($v), $value)).']';
            }

            $keys = array_keys($value);
            sort($keys, SORT_STRING);

            $entries = [];
            foreach ($keys as $key) {
                $v = $value[$key];
                if ($v === null && ! array_key_exists($key, $value)) {
                    continue;
                }
                $entries[] = json_encode((string) $key, JSON_UNESCAPED_SLASHES).':'.$this->canonicalize($v);
            }

            return '{'.implode(',', $entries).'}';
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    public function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $encoded): ?string
    {
        $encoded = strtr($encoded, '-_', '+/');
        $pad = strlen($encoded) % 4;
        if ($pad) {
            $encoded .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }

    private function expires(CarbonImmutable $at): string
    {
        // ISO-8601 with milliseconds + Z, matching the captured mppx shape
        // (e.g. 2026-06-22T15:26:33.158Z).
        return $at->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    private function escapeQuoted(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
