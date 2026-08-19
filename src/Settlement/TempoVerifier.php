<?php

namespace Square1\Mpp\Settlement;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Square1\Mpp\Protocol\Challenge;
use Square1\Mpp\Protocol\Credential;
use Square1\Mpp\Protocol\Tempo\ParsedTempoCredential;
use Square1\Mpp\Protocol\Tempo\TempoChallengeState;
use Square1\Mpp\Support\Base64Url;
use Square1\Mpp\Support\Evm\TempoTransaction;
use Throwable;

/**
 * Verifies a Tempo credential against the stored challenge, then settles it on-chain.
 *
 * The client signs a COMPLETE transaction (a pathUSD transfer to the challenged
 * recipient) and pays its own gas. The server holds no key and no gas account.
 * It only:
 *
 *   (a) confirms the echoed challenge is one we issued and is unexpired (the
 *       caller looks it up in the store; we re-check expiry + the echoed request
 *       matches the stored state),
 *   (b) decodes the signed transaction and checks its transfer call pays the
 *       challenged amount of the challenged token to the challenged recipient,
 *   (c) checks the transfer carries the exact memo THIS challenge advertised
 *       (so a transaction for one challenge cannot satisfy another),
 *   (d) broadcasts it and confirms it mined with status 0x1 to the required
 *       confirmation depth (delegated to the {@see SettlementChecker}),
 *   (e) returns the transaction hash as the settlement reference.
 *
 * Every step FAILS CLOSED: any mismatch, an absent/reverted receipt, or an
 * expired/unknown challenge yields a failure, never a served resource.
 */
class TempoVerifier implements Verifier
{
    /**
     * @param  array<string, mixed>  $methodConfig  the `mpp.methods.tempo` config block
     */
    public function __construct(
        private readonly SettlementChecker $checker,
        private readonly array $methodConfig = [],
    ) {}

    /**
     * Verifier-interface entry point: adapt the spec-format Credential and
     * Challenge onto the tempo verification pipeline. The stored Challenge is
     * authoritative (the gate has already recomputed its binding); the
     * credential's echoed request is still cross-checked byte-for-byte against
     * it, so a client cannot answer challenge A with a payload minted for a
     * differently-priced challenge B that shares an id prefix.
     *
     * @param  array<string, mixed>  $context  unused by the tempo rail
     */
    public function verify(Credential $credential, Challenge $challenge, array $context = []): SettlementResult
    {
        $state = new TempoChallengeState(
            id: $challenge->id,
            realm: $challenge->realm,
            amount: (string) ($challenge->request['amount'] ?? '0'),
            token: (string) ($challenge->request['currency'] ?? ''),
            recipient: (string) ($challenge->request['recipient'] ?? ''),
            chainId: (int) (($challenge->request['methodDetails']['chainId'] ?? 0)),
            expiresAt: $challenge->expiresAt,
            grants: $challenge->grants(),
            scope: $challenge->scope(),
            intent: $challenge->intent,
            memo: (string) ($challenge->request['methodDetails']['memo'] ?? ''),
        );

        $echoed = $credential->challenge;

        $parsed = new ParsedTempoCredential(
            challengeId: (string) ($echoed['id'] ?? ''),
            realm: (string) ($echoed['realm'] ?? $challenge->realm),
            method: (string) ($echoed['method'] ?? $challenge->method),
            intent: (string) ($echoed['intent'] ?? $challenge->intent),
            expires: isset($echoed['expires']) ? (string) $echoed['expires'] : null,
            request: $this->decodeEchoedRequest($echoed['request'] ?? null) ?? $challenge->request,
            payloadType: (string) ($credential->payload['type'] ?? ''),
            signature: (string) ($credential->payload['signature'] ?? ''),
            source: $credential->source,
            rawRequest: is_string($echoed['request'] ?? null) ? $echoed['request'] : null,
        );

        return $this->verifyTempo($parsed, $state);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeEchoedRequest(mixed $requestB64): ?array
    {
        if (! is_string($requestB64) || $requestB64 === '') {
            return null;
        }

        $json = Base64Url::decode($requestB64);
        $decoded = $json === null ? null : json_decode($json, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    public function verifyTempo(ParsedTempoCredential $credential, TempoChallengeState $state): SettlementResult
    {
        // (a) The challenge must be unexpired. (Existence/single-use is enforced
        // by the caller via the ChallengeStore before we are invoked.)
        if ($state->isExpired()) {
            return SettlementResult::failure('The challenge has expired.');
        }

        if (! $credential->isTransaction()) {
            return SettlementResult::failure("Unsupported tempo credential type '{$credential->payloadType}'.");
        }

        if ($credential->signature === '') {
            return SettlementResult::failure('No signed transaction presented.');
        }

        // The echoed request must match the issued challenge byte-for-byte on the
        // economic fields (amount/token/recipient/chainId). A tampered request is
        // a different challenge and must not settle.
        if (! $this->echoedRequestMatches($credential, $state)) {
            return SettlementResult::failure('The echoed challenge request does not match the issued challenge.');
        }

        // (b) Decode the signed transaction and locate the transfer call.
        try {
            $tx = TempoTransaction::deserialize($credential->signature);
        } catch (InvalidArgumentException $e) {
            return SettlementResult::failure('Invalid signed transaction: '.$e->getMessage());
        }

        if ($tx->chainId !== $state->chainId) {
            return SettlementResult::failure("Transaction chain id {$tx->chainId} does not match the challenge ({$state->chainId}).");
        }

        $transfer = $this->findMatchingTransfer($tx, $state);

        if ($transfer === null) {
            return SettlementResult::failure('No transfer call paying the challenged amount of the challenged token to the challenged recipient was found.');
        }

        // (c) The transfer must carry the exact memo this challenge advertised.
        // The memo is a random per-challenge value bound into the challenge id
        // HMAC, so an exact match binds the on-chain payment to this one
        // challenge — a transfer minted for a different challenge carries a
        // different memo and cannot settle here.
        $memo = $transfer['memo'];
        if ($memo === null) {
            return SettlementResult::failure('Transfer is missing the challenge-bound memo.');
        }

        if ($state->memo === '' || ! hash_equals($this->normalizeMemo($state->memo), $this->normalizeMemo($memo))) {
            return SettlementResult::failure('Transfer memo does not match the challenge memo.');
        }

        // (d) Broadcast + confirm on-chain. The checker fails closed on revert,
        // absent receipt, or unmatched logs.
        $minConfirmations = max(1, (int) ($this->methodConfig['confirmations'] ?? 1));

        try {
            $outcome = $this->checker->settle(
                signedTransaction: $credential->signature,
                expectedToken: $state->token,
                expectedRecipient: $state->recipient,
                expectedAmount: $state->amount,
                methodConfig: $this->methodConfig,
            );
        } catch (Throwable $e) {
            return SettlementResult::failure('Tempo settlement error: '.$e->getMessage());
        }

        if (! $outcome->confirmed) {
            return SettlementResult::failure(
                'On-chain settlement was not confirmed'.($outcome->reason !== null ? ': '.$outcome->reason : '.')
            );
        }

        if ($outcome->confirmations !== null && $outcome->confirmations < $minConfirmations) {
            return SettlementResult::failure(
                "On-chain settlement has not reached finality ({$outcome->confirmations}/{$minConfirmations} confirmations)."
            );
        }

        if ($outcome->settlementRef === null || $outcome->settlementRef === '') {
            return SettlementResult::failure('On-chain settlement returned no reference (tx hash).');
        }

        // (e) Success — the tx hash is the settlement reference.
        return SettlementResult::settled(
            settlementRef: $outcome->settlementRef,
            amountMinor: $state->amount,
            currency: $state->token,
            settledAt: CarbonImmutable::now(),
        );
    }

    /**
     * Find the transfer call that pays the challenged amount of the challenged
     * token to the challenged recipient. Mirrors mppx's `assertTransferCalls` /
     * `decodeTransferCall`.
     *
     * @return array{recipient:string, amount:string, memo:?string}|null
     */
    private function findMatchingTransfer(TempoTransaction $tx, TempoChallengeState $state): ?array
    {
        foreach ($tx->calls as $call) {
            $decoded = TempoTransaction::decodeTransferCall($call, $state->token);

            if ($decoded === null) {
                continue;
            }

            if (strcasecmp(trim($decoded['recipient']), trim($state->recipient)) !== 0) {
                continue;
            }

            if ($decoded['amount'] !== $state->amount) {
                continue;
            }

            return $decoded;
        }

        return null;
    }

    /**
     * Lower-case, 0x-prefixed form of a bytes32 memo, for a case- and
     * prefix-insensitive exact comparison.
     */
    private function normalizeMemo(string $memo): string
    {
        $hex = str_starts_with($memo, '0x') || str_starts_with($memo, '0X') ? substr($memo, 2) : $memo;

        return '0x'.strtolower($hex);
    }

    private function echoedRequestMatches(ParsedTempoCredential $credential, TempoChallengeState $state): bool
    {
        $request = $credential->request;

        $amount = isset($request['amount']) ? (string) $request['amount'] : null;
        $currency = isset($request['currency']) ? (string) $request['currency'] : null;
        $recipient = isset($request['recipient']) ? (string) $request['recipient'] : null;
        $chainId = isset($request['methodDetails']['chainId']) ? (int) $request['methodDetails']['chainId'] : null;

        return $amount === $state->amount
            && $currency !== null && strcasecmp($currency, $state->token) === 0
            && $recipient !== null && strcasecmp($recipient, $state->recipient) === 0
            && $chainId === $state->chainId;
    }
}
