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
 * Verifies a Tempo credential against the stored challenge, and then settles it
 * on-chain.
 *
 * The client signs a COMPLETE transaction, which is a pathUSD transfer to the
 * challenged recipient, and the client pays its own gas. The server holds no key
 * and no gas account. The server does only the following:
 *
 *   (a) It confirms that the echoed challenge is one that this server issued and
 *       that it has not expired. The caller looks the challenge up in the store.
 *       This class re-checks the expiry, and checks that the echoed request
 *       matches the stored state.
 *   (b) It decodes the signed transaction. It then checks that the transfer call
 *       pays the challenged amount of the challenged token to the challenged
 *       recipient.
 *   (c) It checks that the transfer carries the exact memo that THIS challenge
 *       advertised, so that a transaction for one challenge cannot satisfy
 *       another challenge.
 *   (d) It broadcasts the transaction and confirms that the transaction mined
 *       with status 0x1, to the required confirmation depth. It delegates this
 *       step to the {@see SettlementChecker}.
 *   (e) It returns the transaction hash as the settlement reference.
 *
 * Every step FAILS CLOSED. A mismatch, an absent or reverted receipt, or an
 * expired or unknown challenge produces a failure. The server never serves the
 * resource after such a failure.
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
     * The entry point of the Verifier interface. It adapts the Credential and
     * the Challenge, in the spec format, onto the tempo verification steps.
     *
     * The stored Challenge is authoritative, because the gate has already
     * recomputed its binding. This class still compares the echoed request in the
     * credential against it, byte for byte. A client therefore cannot answer
     * challenge A with a payload that the server minted for a challenge B at a
     * different price that shares an id prefix.
     *
     * @param  array<string, mixed>  $context  the tempo rail does not use this
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
        // (a) The challenge must not have expired. The caller checks that the
        // challenge exists, and enforces single use, through the ChallengeStore
        // before it calls this class.
        if ($state->isExpired()) {
            return SettlementResult::failure('The challenge has expired.');
        }

        if (! $credential->isTransaction()) {
            return SettlementResult::failure("Unsupported tempo credential type '{$credential->payloadType}'.");
        }

        if ($credential->signature === '') {
            return SettlementResult::failure('No signed transaction presented.');
        }

        // The echoed request must match the issued challenge byte for byte on
        // the economic fields: the amount, the token, the recipient and the
        // chainId. An altered request is a different challenge, and it must not
        // settle.
        if (! $this->echoedRequestMatches($credential, $state)) {
            return SettlementResult::failure('The echoed challenge request does not match the issued challenge.');
        }

        // (b) Decode the signed transaction and find the transfer call.
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

        // (c) The transfer must carry the exact memo that this challenge
        // advertised. The memo is a random value per challenge, and the
        // challenge id HMAC binds it. An exact match therefore binds the
        // on-chain payment to this one challenge. A transfer that the server
        // minted for a different challenge carries a different memo, and it
        // cannot settle here.
        $memo = $transfer['memo'];
        if ($memo === null) {
            return SettlementResult::failure('Transfer is missing the challenge-bound memo.');
        }

        if ($state->memo === '' || ! hash_equals($this->normalizeMemo($state->memo), $this->normalizeMemo($memo))) {
            return SettlementResult::failure('Transfer memo does not match the challenge memo.');
        }

        // (d) Broadcast the transaction and confirm it on-chain. The checker
        // fails closed on a revert, on an absent receipt, and on logs that do
        // not match.
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

        // (e) The transaction succeeded. The transaction hash is the settlement
        // reference.
        return SettlementResult::settled(
            settlementRef: $outcome->settlementRef,
            amountMinor: $state->amount,
            currency: $state->token,
            settledAt: CarbonImmutable::now(),
        );
    }

    /**
     * Finds the transfer call that pays the challenged amount of the challenged
     * token to the challenged recipient.
     *
     * The logic is the same as the `assertTransferCalls` and `decodeTransferCall`
     * functions of mppx.
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
     * Returns a bytes32 memo in lower case, with a 0x prefix.
     *
     * The form allows an exact comparison that ignores case and prefix.
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
