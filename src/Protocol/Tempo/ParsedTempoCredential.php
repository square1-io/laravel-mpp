<?php

namespace Square1\Mpp\Protocol\Tempo;

/**
 * A parsed mppx-dialect tempo credential: the echoed challenge fields, the
 * signed-transaction payload, and the payer DID source.
 *
 * The `signature` is the COMPLETE signed Tempo transaction (a `0x76`/`0x78`
 * envelope), not an ECDSA signature — that is mppx's field name for a
 * `payload.type === 'transaction'` credential. The server decodes it, validates
 * its transfer call against the challenge, then broadcasts it.
 */
final class ParsedTempoCredential
{
    /**
     * @param  array<string, mixed>  $request  the decoded echoed mppx request object
     */
    public function __construct(
        public readonly string $challengeId,
        public readonly string $realm,
        public readonly string $method,
        public readonly string $intent,
        public readonly ?string $expires,
        public readonly array $request,
        public readonly string $payloadType,
        public readonly string $signature,
        public readonly ?string $source,
        public readonly ?string $rawRequest,
    ) {}

    public function isTransaction(): bool
    {
        return $this->payloadType === 'transaction';
    }
}
