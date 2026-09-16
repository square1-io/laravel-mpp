<?php

namespace Square1\Mpp\Settlement\Tempo;

/**
 * The smallest JSON-RPC surface that the Tempo settlement path needs. It
 * broadcasts a signed transaction and reads its receipt.
 *
 * The surface is an interface, so that a unit test can use a fake for the
 * broadcast and confirm logic. A test therefore never uses the network.
 *
 * An implementation speaks plain Ethereum JSON-RPC over HTTP. The package ships
 * an implementation that uses the Laravel HTTP client, {@see HttpRpcClient}.
 * Nothing in this interface signs anything or holds a key. The client has
 * already signed the transaction, and the server relays it.
 */
interface RpcClient
{
    /**
     * Broadcasts a raw signed transaction, as 0x-hex.
     *
     * The method returns the transaction hash on success.
     *
     * @throws \RuntimeException if the RPC endpoint rejects the transaction
     */
    public function sendRawTransaction(string $rawTransaction): string;

    /**
     * Fetches a transaction receipt by hash.
     *
     * The method returns null when the network has not yet mined the
     * transaction.
     *
     * @return array<string, mixed>|null the raw JSON-RPC receipt object
     */
    public function getTransactionReceipt(string $hash): ?array;

    /**
     * Returns the latest block number, as a decimal int.
     *
     * The package uses it to compute the confirmation depth.
     */
    public function blockNumber(): int;
}
