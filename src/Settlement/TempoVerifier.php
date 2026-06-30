<?php

namespace Square1\Mpp\Settlement;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Square1\Mpp\Protocol\Tempo\ParsedTempoCredential;
use Square1\Mpp\Protocol\Tempo\TempoChallengeState;
use Square1\Mpp\Support\Evm\Attribution;
use Square1\Mpp\Support\Evm\TempoTransaction;
use Throwable;

/**
 * Verifies and settles an mppx-dialect Tempo credential, then settles it on-chain.
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
 *   (c) checks the transfer's memo is bound to THIS challenge id under THIS realm
 *       (so a transaction for one challenge cannot satisfy another),
 *   (d) broadcasts it and confirms it mined with status 0x1 to the required
 *       confirmation depth (delegated to the {@see SettlementChecker}),
 *   (e) returns the transaction hash as the settlement reference.
 *
 * Every step FAILS CLOSED: any mismatch, an absent/reverted receipt, or an
 * expired/unknown challenge yields a failure, never a served resource.
 */
class TempoVerifier
{
    /**
     * @param  array<string, mixed>  $methodConfig  the `mpp.methods.tempo` config block
     */
    public function __construct(
        private readonly SettlementChecker $checker,
        private readonly array $methodConfig = [],
    ) {}

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

        // (c) The memo must be bound to THIS challenge under THIS realm.
        $memo = $transfer['memo'];
        if ($memo === null) {
            return SettlementResult::failure('Transfer is missing the challenge-bound attribution memo.');
        }

        if (! Attribution::verifyServer($memo, $state->realm)) {
            return SettlementResult::failure('Transfer memo is not bound to this server (realm mismatch).');
        }

        if (! Attribution::verifyChallengeBinding($memo, $state->id)) {
            return SettlementResult::failure('Transfer memo is not bound to this challenge.');
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
            amountMinor: (int) $state->amount,
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
