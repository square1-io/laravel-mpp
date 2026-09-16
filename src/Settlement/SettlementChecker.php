<?php

namespace Square1\Mpp\Settlement;

/**
 * Broadcasts a settlement transaction that the client signed, to an external
 * rail, and reports what the rail states about it after the network mines it.
 *
 * A rail such as Tempo uses this interface. On such a rail, the client signs and
 * presents a COMPLETE transaction that is ready to broadcast. The server holds
 * no key and pays no gas. It relays the signed bytes, and then confirms the
 * on-chain result. Stripe is different: there the server starts and confirms a
 * PaymentIntent.
 *
 * The checker is deliberately narrow. It broadcasts the transaction, and it
 * reads facts back from the rail into a {@see SettlementOutcome}. Those facts
 * are the mined status, the recipient, the amount and the token that it observed
 * in the transfer logs, and the confirmation depth.
 *
 * The checker does NOT decide whether those facts satisfy a challenge. The
 * Verifier owns that policy, which covers the amount, the recipient, the token,
 * the memo binding and finality. The Verifier never trusts the client.
 *
 * An implementation MUST fail closed. When the broadcast fails, when the
 * transaction reverts, when the receipt is absent, or when anything is
 * uncertain, it returns {@see SettlementOutcome::unconfirmed()} and not a
 * confirmed outcome.
 */
interface SettlementChecker
{
    /**
     * Broadcasts the signed transaction, as 0x-hex, and confirms its on-chain
     * result.
     *
     * @param  string  $signedTransaction  the raw signed transaction bytes (0x-hex)
     * @param  string  $expectedToken  the token contract that the transfer must target
     * @param  string  $expectedRecipient  the address that the funds must settle to
     * @param  string  $expectedAmount  the transfer amount in minor units, as a decimal string
     * @param  array<string, mixed>  $methodConfig  the `mpp.methods.<name>` config of the rail,
     *                                              which holds rpc_url, chain_id, confirmations
     *                                              and the other settings
     */
    public function settle(
        string $signedTransaction,
        string $expectedToken,
        string $expectedRecipient,
        string $expectedAmount,
        array $methodConfig,
    ): SettlementOutcome;
}
