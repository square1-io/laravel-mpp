<?php

namespace Square1\Mpp\Support\Evm;

use InvalidArgumentException;

/**
 * Decodes a serialized Tempo transaction (the `payload.signature` a tempo/mppx
 * client presents) into the fields the settlement layer needs to validate and
 * broadcast it: the chain id, the batched `calls[]`, and — for each call that
 * is a TIP-20 token transfer — the recipient, amount and 32-byte memo.
 *
 * This mirrors the reference implementation exactly:
 *   - the envelope is `0x76` (sender) or `0x78` (fee-payer) followed by an RLP
 *     list (ox `TxEnvelopeTempo.deserialize`), and
 *   - a transfer call is `transfer(address,uint256)` (selector 0xa9059cbb) or
 *     `transferWithMemo(address,uint256,bytes32)` (selector 0x95777d59) on the
 *     token contract (mppx `tempo/server/Charge.decodeTransferCall`).
 *
 * Only DECODING is implemented — broadcasting re-sends the client's exact bytes,
 * and the network (not us) checks the signature by mining the transaction.
 */
final class TempoTransaction
{
    /** Tempo serialized envelope type (sender-signed). */
    public const TYPE_SENDER = '76';

    /** Tempo fee-payer envelope magic. */
    public const TYPE_FEE_PAYER = '78';

    /** selector of transfer(address,uint256). */
    public const SELECTOR_TRANSFER = '0xa9059cbb';

    /** selector of transferWithMemo(address,uint256,bytes32). */
    public const SELECTOR_TRANSFER_WITH_MEMO = '0x95777d59';

    /**
     * @param  list<array{to:?string,value:?string,data:?string}>  $calls
     */
    public function __construct(
        public readonly int $chainId,
        public readonly array $calls,
        public readonly string $serialized,
    ) {}

    /**
     * Returns true if the serialized bytes are a Tempo (0x76/0x78) transaction.
     */
    public static function isTempoTransaction(string $serialized): bool
    {
        $prefix = self::prefix($serialized);

        return $prefix === self::TYPE_SENDER || $prefix === self::TYPE_FEE_PAYER;
    }

    /**
     * Deserialize a Tempo transaction envelope.
     *
     * @throws InvalidArgumentException on a non-Tempo envelope or malformed RLP
     */
    public static function deserialize(string $serialized): self
    {
        $prefix = self::prefix($serialized);

        if ($prefix !== self::TYPE_SENDER && $prefix !== self::TYPE_FEE_PAYER) {
            throw new InvalidArgumentException('Only Tempo (0x76/0x78) transactions are supported.');
        }

        $hex = self::normalizeHex($serialized);
        // Strip the 1-byte type prefix, RLP-decode the remainder.
        $body = '0x'.substr($hex, 2);
        $decoded = Rlp::decode($body);

        if (! is_array($decoded) || count($decoded) < 5) {
            throw new InvalidArgumentException('Malformed Tempo transaction envelope.');
        }

        // Field order (ox TxEnvelopeTempo): [chainId, maxPriorityFeePerGas,
        // maxFeePerGas, gas, calls, accessList, nonceKey, nonce, validBefore,
        // validAfter, feeToken, feePayerSignatureOrSender, authorizationList,
        // (keyAuthorization?), signatureEnvelope].
        $chainId = self::hexToInt(self::asString($decoded[0]));
        $callsRaw = $decoded[4];

        $calls = [];
        if (is_array($callsRaw)) {
            foreach ($callsRaw as $callTuple) {
                if (! is_array($callTuple)) {
                    continue;
                }
                $to = isset($callTuple[0]) ? self::asString($callTuple[0]) : '0x';
                $value = isset($callTuple[1]) ? self::asString($callTuple[1]) : '0x';
                $data = isset($callTuple[2]) ? self::asString($callTuple[2]) : '0x';

                $calls[] = [
                    'to' => ($to !== '0x') ? strtolower($to) : null,
                    'value' => ($value !== '0x') ? $value : null,
                    'data' => ($data !== '0x') ? strtolower($data) : null,
                ];
            }
        }

        return new self($chainId, $calls, '0x'.$hex);
    }

    /**
     * The on-chain transaction hash = keccak256(serialized bytes), 0x-prefixed.
     */
    public function hash(): string
    {
        $hex = self::normalizeHex($this->serialized);

        return Keccak::hashHex(hex2bin($hex));
    }

    /**
     * Decode a single call's data as a TIP-20 transfer, returning recipient,
     * amount (decimal string) and an optional 32-byte memo (0x hex). Returns
     * null when the call's `to` is not the given token contract or the calldata
     * is not a recognised transfer selector — exactly mppx's `decodeTransferCall`.
     *
     * @param  array{to:?string,value:?string,data:?string}  $call
     * @return array{recipient:string, amount:string, memo:?string}|null
     */
    public static function decodeTransferCall(array $call, string $token): ?array
    {
        $to = $call['to'] ?? null;
        $data = $call['data'] ?? null;

        if ($to === null || $data === null) {
            return null;
        }

        if (strtolower($to) !== strtolower($token)) {
            return null;
        }

        $data = self::normalizeHex($data);
        if (strlen($data) < 8) {
            return null;
        }

        $selector = '0x'.substr($data, 0, 8);
        $args = substr($data, 8);

        if ($selector === self::SELECTOR_TRANSFER) {
            if (strlen($args) < 128) {
                return null;
            }
            $recipient = self::addressFromWord(substr($args, 0, 64));
            $amount = self::uintFromWord(substr($args, 64, 64));

            return ['recipient' => $recipient, 'amount' => $amount, 'memo' => null];
        }

        if ($selector === self::SELECTOR_TRANSFER_WITH_MEMO) {
            if (strlen($args) < 192) {
                return null;
            }
            $recipient = self::addressFromWord(substr($args, 0, 64));
            $amount = self::uintFromWord(substr($args, 64, 64));
            $memo = '0x'.substr($args, 128, 64);

            return ['recipient' => $recipient, 'amount' => $amount, 'memo' => strtolower($memo)];
        }

        return null;
    }

    private static function prefix(string $serialized): string
    {
        $hex = self::normalizeHex($serialized);

        return strtolower(substr($hex, 0, 2));
    }

    /** Strip a 0x prefix and return lowercase hex with no prefix. */
    private static function normalizeHex(string $value): string
    {
        if (str_starts_with($value, '0x') || str_starts_with($value, '0X')) {
            $value = substr($value, 2);
        }

        return strtolower($value);
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '0x';
    }

    /** Decode a 32-byte ABI word holding a left-padded address. */
    private static function addressFromWord(string $word): string
    {
        return '0x'.strtolower(substr($word, 24, 40));
    }

    /** Decode a 32-byte ABI word as an unsigned integer, returned as a decimal string. */
    private static function uintFromWord(string $word): string
    {
        $word = ltrim($word, '0');

        if ($word === '') {
            return '0';
        }

        return gmp_strval(gmp_init('0x'.$word, 16), 10);
    }

    private static function hexToInt(string $hex): int
    {
        $hex = self::normalizeHex($hex);

        if ($hex === '') {
            return 0;
        }

        return (int) gmp_strval(gmp_init('0x'.$hex, 16), 10);
    }
}
