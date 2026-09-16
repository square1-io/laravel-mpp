<?php

namespace Square1\Mpp\Settlement;

use Square1\Mpp\Settlement\Tempo\RpcClient;
use Square1\Mpp\Support\Evm\Keccak;
use Throwable;

/**
 * Broadcasts a Tempo transaction that the client signed, and confirms its
 * on-chain result.
 *
 * This is the live integration with the Tempo JSON-RPC endpoint, or with any EVM
 * JSON-RPC endpoint, in pure PHP. It holds NO private key and pays NO gas,
 * because the client has already signed a complete transaction that pays its own
 * fees. This class does only the following:
 *
 *   1. It broadcasts the signed bytes with `eth_sendRawTransaction`.
 *   2. It polls `eth_getTransactionReceipt` until the network mines the
 *      transaction.
 *   3. It checks that the status of the receipt is `0x1`, which means success
 *      and not a revert.
 *   4. It requires the receipt to carry a TIP-20 Transfer or TransferWithMemo
 *      log, to the expected recipient, for the expected amount, on the expected
 *      token. This repeats the calldata validation that the Verifier performed
 *      before the broadcast, as a second defence.
 *   5. It computes the confirmation depth, so that the Verifier can enforce
 *      finality.
 *
 * The class fails closed on an RPC failure, a revert, a missing receipt, or a
 * log that does not match.
 */
final class TempoRpcSettlementChecker implements SettlementChecker
{
    /** keccak256("Transfer(address,address,uint256)"). */
    private const TOPIC_TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /** keccak256("TransferWithMemo(address,address,uint256,bytes32)"). */
    private const TOPIC_TRANSFER_WITH_MEMO = '0x57bc7354aa85aed339e000bccffabbc529466af35f0772c8f8ee1145927de7f0';

    public function __construct(
        private readonly RpcClient $rpc,
    ) {}

    public function settle(
        string $signedTransaction,
        string $expectedToken,
        string $expectedRecipient,
        string $expectedAmount,
        array $methodConfig,
    ): SettlementOutcome {
        $confirmations = max(1, (int) ($methodConfig['confirmations'] ?? 1));
        $pollAttempts = max(1, (int) ($methodConfig['poll_attempts'] ?? 40));
        $pollDelayMs = max(0, (int) ($methodConfig['poll_delay_ms'] ?? 500));

        try {
            $hash = $this->rpc->sendRawTransaction($signedTransaction);
        } catch (Throwable $e) {
            // A re-broadcast of a transaction that the network already knows is
            // not a failure. The signed bytes are deterministic, so derive the hash
            // and confirm it.
            $hash = $this->deriveHash($signedTransaction);

            if ($hash === null) {
                return SettlementOutcome::unconfirmed('Broadcast failed: '.$e->getMessage());
            }
        }

        $receipt = $this->awaitReceipt($hash, $pollAttempts, $pollDelayMs);

        if ($receipt === null) {
            return SettlementOutcome::unconfirmed('Transaction was not mined before the poll timeout: '.$hash);
        }

        $status = isset($receipt['status']) ? strtolower((string) $receipt['status']) : null;
        if ($status !== '0x1') {
            return SettlementOutcome::unconfirmed('Transaction reverted on-chain (status '.($status ?? 'absent').'): '.$hash);
        }

        $match = $this->findTransferLog($receipt, $expectedToken, $expectedRecipient, $expectedAmount);
        if ($match === null) {
            return SettlementOutcome::unconfirmed('No matching TIP-20 transfer log to the expected recipient: '.$hash);
        }

        $depth = $this->confirmationDepth($receipt);

        return SettlementOutcome::confirmed(
            settlementRef: $hash,
            amountMinor: $expectedAmount,
            currency: $expectedToken,
            recipient: $expectedRecipient,
            confirmations: $depth,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function awaitReceipt(string $hash, int $attempts, int $delayMs): ?array
    {
        for ($i = 0; $i < $attempts; $i++) {
            $receipt = $this->rpc->getTransactionReceipt($hash);

            if (is_array($receipt) && ($receipt['blockNumber'] ?? null) !== null) {
                return $receipt;
            }

            if ($i < $attempts - 1 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return null;
    }

    /**
     * Finds a Transfer or TransferWithMemo log that the token contract emitted,
     * and that pays the expected amount to the expected recipient.
     *
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>|null the log that matched
     */
    private function findTransferLog(array $receipt, string $token, string $recipient, string $amount): ?array
    {
        $logs = is_array($receipt['logs'] ?? null) ? $receipt['logs'] : [];
        $recipientWord = $this->addressTopic($recipient);
        $amountHex = $this->amountToWord($amount);

        foreach ($logs as $log) {
            if (! is_array($log)) {
                continue;
            }

            $address = strtolower((string) ($log['address'] ?? ''));
            if ($address !== strtolower($token)) {
                continue;
            }

            $topics = array_map('strtolower', (array) ($log['topics'] ?? []));
            $topic0 = $topics[0] ?? '';

            if ($topic0 !== self::TOPIC_TRANSFER && $topic0 !== self::TOPIC_TRANSFER_WITH_MEMO) {
                continue;
            }

            // topics[2] is the indexed `to` address.
            $to = $topics[2] ?? '';
            if (strtolower($to) !== strtolower($recipientWord)) {
                continue;
            }

            // `amount` is the first 32-byte word of the data, which is not
            // indexed.
            $data = strtolower(ltrim((string) ($log['data'] ?? ''), '0'));
            $dataHex = str_starts_with((string) ($log['data'] ?? ''), '0x') ? substr((string) $log['data'], 2) : (string) ($log['data'] ?? '');
            $amountWord = strtolower(substr(str_pad($dataHex, 64, '0', STR_PAD_LEFT), 0, 64));

            if ($this->stripWord($amountWord) === $this->stripWord($amountHex)) {
                return $log;
            }
        }

        return null;
    }

    private function confirmationDepth(array $receipt): int
    {
        $blockNumber = $receipt['blockNumber'] ?? null;
        if (! is_string($blockNumber)) {
            return 1;
        }

        try {
            $txBlock = (int) gmp_strval(gmp_init($blockNumber, 16), 10);
            $head = $this->rpc->blockNumber();
        } catch (Throwable) {
            return 1;
        }

        return max(1, ($head - $txBlock) + 1);
    }

    private function deriveHash(string $signedTransaction): ?string
    {
        try {
            $hex = str_starts_with($signedTransaction, '0x') ? substr($signedTransaction, 2) : $signedTransaction;

            if (strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
                return null;
            }

            return Keccak::hashHex(hex2bin($hex));
        } catch (Throwable) {
            return null;
        }
    }

    private function addressTopic(string $address): string
    {
        $address = strtolower(str_starts_with($address, '0x') ? substr($address, 2) : $address);

        return '0x'.str_pad($address, 64, '0', STR_PAD_LEFT);
    }

    private function amountToWord(string $amount): string
    {
        $hex = gmp_strval(gmp_init($amount, 10), 16);

        return '0x'.str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    private function stripWord(string $word): string
    {
        $word = strtolower(str_starts_with($word, '0x') ? substr($word, 2) : $word);
        $word = ltrim($word, '0');

        return $word === '' ? '0' : $word;
    }
}
