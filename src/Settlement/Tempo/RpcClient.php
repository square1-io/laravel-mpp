<?php

namespace Square1\Mpp\Settlement\Tempo;

/**
 * Minimal JSON-RPC surface the Tempo settlement path needs: broadcast a signed
 * transaction and read its receipt. Kept behind an interface so the broadcast +
 * confirm logic is unit-testable with a fake and never touches the network in a
 * test.
 *
 * Implementations talk plain Ethereum JSON-RPC over HTTP. The package ships a
 * Laravel Http client implementation ({@see HttpRpcClient}); nothing here signs
 * or holds a key — the client already signed the transaction, we only relay it.
 */
interface RpcClient
{
    /**
     * Broadcast a raw signed transaction (0x-hex). Returns the transaction hash
     * on success.
     *
     * @throws \RuntimeException if the RPC rejects the transaction
     */
    public function sendRawTransaction(string $rawTransaction): string;

    /**
     * Fetch a transaction receipt by hash, or null if not yet mined.
     *
     * @return array<string, mixed>|null the raw JSON-RPC receipt object
     */
    public function getTransactionReceipt(string $hash): ?array;

    /**
     * The latest block number (decimal int), used to compute confirmation depth.
     */
    public function blockNumber(): int;
}
