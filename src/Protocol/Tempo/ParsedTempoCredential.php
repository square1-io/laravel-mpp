<?php

namespace Square1\Mpp\Protocol\Tempo;

/**
 * A parsed tempo credential: the echoed challenge fields, the payload that holds
 * the signed transaction, and the DID source of the payer.
 *
 * The `signature` is the COMPLETE signed Tempo transaction, in a `0x76` or
 * `0x78` envelope. It is not an ECDSA signature. `signature` is the field name
 * that the spec uses for a credential whose `payload.type` is `transaction`. The
 * server decodes the transaction, validates its transfer call against the
 * challenge, and then broadcasts it.
 */
final class ParsedTempoCredential
{
    /**
     * @param  array<string, mixed>  $request  the decoded mppx request object that the client echoed
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
