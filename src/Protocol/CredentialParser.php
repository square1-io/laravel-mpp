<?php

namespace Square1\Mpp\Protocol;

/**
 * Parses the `Authorization: Payment ...` header into a Credential.
 *
 * Accepts these forms:
 *   Payment method="stripe", challengeId="chal_…", sig="…", spt="spt_…"
 *   Payment method="acme",   challengeId="chal_…", sig="…", proof="charge_…"
 *   Payment method="stripe", session="sess_…"
 *
 * `spt` and `proof` are aliases for the same rail-neutral settlement proof; both
 * are mapped onto the Credential. Tempo credentials use the separate mppx codec,
 * not this native key/value form.
 */
class CredentialParser
{
    public function parse(?string $header): ?Credential
    {
        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        if (! preg_match('/^\s*Payment\s+(.*)$/is', $header, $m)) {
            return null;
        }

        $params = $this->parseParams($m[1]);

        if ($params === []) {
            return null;
        }

        return new Credential(
            method: $params['method'] ?? 'stripe',
            challengeId: $params['challengeId'] ?? $params['challenge_id'] ?? null,
            spt: $params['spt'] ?? null,
            session: $params['session'] ?? null,
            signature: $params['sig'] ?? null,
            proof: $params['proof'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseParams(string $raw): array
    {
        preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|([^,\s]+))/', $raw, $matches, PREG_SET_ORDER);

        $params = [];

        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            $params[$key] = $value;
        }

        return $params;
    }
}
