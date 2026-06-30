<?php

namespace Square1\Mpp\Settlement\Tempo;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * {@see RpcClient} backed by Laravel's HTTP client. Talks plain Ethereum
 * JSON-RPC to the configured Tempo RPC URL. Holds no key and signs nothing — the
 * client signed the transaction; we only broadcast it and read its receipt.
 */
final class HttpRpcClient implements RpcClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $rpcUrl,
        private readonly int $timeout = 30,
    ) {}

    public function sendRawTransaction(string $rawTransaction): string
    {
        $result = $this->call('eth_sendRawTransaction', [$rawTransaction]);

        if (! is_string($result) || ! str_starts_with($result, '0x')) {
            throw new RuntimeException('eth_sendRawTransaction did not return a transaction hash.');
        }

        return $result;
    }

    public function getTransactionReceipt(string $hash): ?array
    {
        $result = $this->call('eth_getTransactionReceipt', [$hash]);

        return is_array($result) ? $result : null;
    }

    public function blockNumber(): int
    {
        $result = $this->call('eth_blockNumber', []);

        if (! is_string($result)) {
            return 0;
        }

        return (int) gmp_strval(gmp_init($result, 16), 10);
    }

    /**
     * @param  array<int, mixed>  $params
     */
    private function call(string $method, array $params): mixed
    {
        if ($this->rpcUrl === '') {
            throw new RuntimeException('Tempo RPC URL is not configured (mpp.methods.tempo.rpc_url).');
        }

        $response = $this->http
            ->asJson()
            ->acceptJson()
            ->timeout($this->timeout)
            ->post($this->rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Tempo RPC {$method} failed with HTTP {$response->status()}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException("Tempo RPC {$method} returned a non-JSON body.");
        }

        if (isset($body['error'])) {
            $message = is_array($body['error']) ? ($body['error']['message'] ?? 'unknown error') : (string) $body['error'];
            throw new RuntimeException("Tempo RPC {$method} error: {$message}");
        }

        return $body['result'] ?? null;
    }
}
