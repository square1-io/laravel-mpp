<?php

namespace Square1\Mpp\Settlement;

/**
 * Broadcasts a client-signed settlement transaction to an external rail and
 * reports the rail's view of it once mined.
 *
 * Used by rails (like Tempo) where the client signs and presents a COMPLETE,
 * ready-to-broadcast transaction — the server holds no key and pays no gas; it
 * only relays the signed bytes and then confirms the on-chain result. This
 * differs from Stripe, where the server initiates and confirms a PaymentIntent.
 *
 * The checker is intentionally narrow: it broadcasts and reads facts back from
 * the rail (mined status, the recipient/amount/token observed in the transfer
 * logs, confirmation depth) into a {@see SettlementOutcome}. It does NOT decide
 * whether those facts satisfy a challenge — that policy (amount/recipient/token/
 * memo-binding/finality) lives in the Verifier, which never trusts the client.
 *
 * Implementations MUST fail closed: if broadcast fails, the transaction reverts,
 * the receipt is absent, or anything is uncertain, return
 * {@see SettlementOutcome::unconfirmed()} rather than a confirmed outcome.
 */
interface SettlementChecker
{
    /**
     * Broadcast the signed transaction (0x-hex) and confirm its on-chain result.
     *
     * @param  string  $signedTransaction  the raw signed transaction bytes (0x-hex)
     * @param  string  $expectedToken  the token contract the transfer must target
     * @param  string  $expectedRecipient  the address funds must settle to
     * @param  string  $expectedAmount  the transfer amount in minor units (decimal string)
     * @param  array<string, mixed>  $methodConfig  the rail's `mpp.methods.<name>` config
     *                                              (rpc_url, chain_id, confirmations, …)
     */
    public function settle(
        string $signedTransaction,
        string $expectedToken,
        string $expectedRecipient,
        string $expectedAmount,
        array $methodConfig,
    ): SettlementOutcome;
}
