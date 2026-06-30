<?php

namespace Square1\Mpp\Tests\Fakes;

use RuntimeException;
use Square1\Mpp\Settlement\Tempo\RpcClient;

/**
 * A fake Tempo JSON-RPC client for unit tests: it records broadcasts and returns
 * a canned receipt, so the broadcast + confirm path is exercised with no network.
 */
final class FakeRpcClient implements RpcClient
{
    /** @var list<string> */
    public array $broadcasts = [];

    public ?string $broadcastError = null;

    /** @var array<string, mixed>|null */
    public ?array $receipt = null;

    public int $head = 100;

    public ?string $forcedHash = null;

    /**
     * @param  array<string, mixed>|null  $receipt
     */
    public function __construct(?array $receipt = null)
    {
        $this->receipt = $receipt;
    }

    public function sendRawTransaction(string $rawTransaction): string
    {
        $this->broadcasts[] = $rawTransaction;

        if ($this->broadcastError !== null) {
            throw new RuntimeException($this->broadcastError);
        }

        return $this->forcedHash ?? ('0x'.str_repeat('ab', 32));
    }

    public function getTransactionReceipt(string $hash): ?array
    {
        return $this->receipt;
    }

    public function blockNumber(): int
    {
        return $this->head;
    }

    /**
     * Build a successful TIP-20 transfer receipt to `$recipient` for `$amount` on
     * `$token`, mined at `$blockNumber`.
     */
    public static function successReceipt(string $token, string $recipient, string $amount, string $hash, int $blockNumber = 100): array
    {
        $to = '0x'.str_pad(strtolower(ltrim($recipient, '0x')), 64, '0', STR_PAD_LEFT);
        $amountHex = '0x'.str_pad(gmp_strval(gmp_init($amount, 10), 16), 64, '0', STR_PAD_LEFT);

        return [
            'transactionHash' => $hash,
            'status' => '0x1',
            'blockNumber' => '0x'.dechex($blockNumber),
            'logs' => [[
                'address' => strtolower($token),
                'topics' => [
                    '0x57bc7354aa85aed339e000bccffabbc529466af35f0772c8f8ee1145927de7f0', // TransferWithMemo
                    '0x'.str_repeat('00', 32), // from
                    $to,                        // to (indexed)
                    '0x'.str_repeat('00', 32), // memo (indexed)
                ],
                'data' => $amountHex,
            ]],
        ];
    }
}
