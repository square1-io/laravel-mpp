<?php

namespace Square1\Mpp\Support\Evm;

use InvalidArgumentException;

/**
 * Decodes a serialized Tempo transaction into the fields that the settlement
 * layer needs.
 *
 * The input is the `payload.signature` that a tempo or mppx client presents. The
 * fields are the chain id, the batched `calls[]`, and, for each call that is a
 * TIP-20 token transfer, the recipient, the amount and the 32-byte memo. The
 * settlement layer uses them to validate and broadcast the transaction.
 *
 * The class follows the reference implementation exactly:
 *   - the envelope is `0x76` for a sender, or `0x78` for a fee-payer, followed
 *     by an RLP list. This is `TxEnvelopeTempo.deserialize` in ox.
 *   - a transfer call is `transfer(address,uint256)`, with selector 0xa9059cbb,
 *     or `transferWithMemo(address,uint256,bytes32)`, with selector 0x95777d59,
 *     on the token contract. This is `tempo/server/Charge.decodeTransferCall` in
 *     mppx.
 *
 * The class only DECODES. To broadcast, the package sends the exact bytes of the
 * client again. The network checks the signature when it mines the transaction,
 * and the package does not.
 */
final class TempoTransaction
{
    /** The serialized envelope type of Tempo, for a transaction that the sender signed. */
    public const TYPE_SENDER = '76';

    /** The envelope magic value of Tempo, for a fee-payer transaction. */
    public const TYPE_FEE_PAYER = '78';

    /** The selector of transfer(address,uint256). */
    public const SELECTOR_TRANSFER = '0xa9059cbb';

    /** The selector of transferWithMemo(address,uint256,bytes32). */
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
     * Reports whether the serialized bytes are a Tempo transaction, which is a
     * 0x76 or 0x78 envelope.
     */
    public static function isTempoTransaction(string $serialized): bool
    {
        $prefix = self::prefix($serialized);

        return $prefix === self::TYPE_SENDER || $prefix === self::TYPE_FEE_PAYER;
    }

    /**
     * Deserializes a Tempo transaction envelope.
     *
     * @throws InvalidArgumentException on an envelope that is not a Tempo envelope, or on malformed RLP
     */
    public static function deserialize(string $serialized): self
    {
        $prefix = self::prefix($serialized);

        if ($prefix !== self::TYPE_SENDER && $prefix !== self::TYPE_FEE_PAYER) {
            throw new InvalidArgumentException('Only Tempo (0x76/0x78) transactions are supported.');
        }

        $hex = self::normalizeHex($serialized);
        // Remove the one-byte type prefix, and RLP-decode the rest.
        $body = '0x'.substr($hex, 2);
        $decoded = Rlp::decode($body);

        if (! is_array($decoded) || count($decoded) < 5) {
            throw new InvalidArgumentException('Malformed Tempo transaction envelope.');
        }

        // This is the field order of TxEnvelopeTempo in ox: [chainId,
        // maxPriorityFeePerGas, maxFeePerGas, gas, calls, accessList, nonceKey,
        // nonce, validBefore, validAfter, feeToken, feePayerSignatureOrSender,
        // authorizationList, (keyAuthorization?), signatureEnvelope].
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
     * Returns the on-chain transaction hash, which is keccak256 of the
     * serialized bytes, with a 0x prefix.
     */
    public function hash(): string
    {
        $hex = self::normalizeHex($this->serialized);

        return Keccak::hashHex(hex2bin($hex));
    }

    /**
     * Decodes the data of one call as a TIP-20 transfer.
     *
     * The method returns the recipient, the amount as a decimal string, and an
     * optional 32-byte memo as 0x hex. It returns null when the `to` of the call
     * is not the given token contract, or when the calldata does not carry a
     * transfer selector that the class recognises. This is the behaviour of
     * `decodeTransferCall` in mppx.
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

    /** Removes a 0x prefix, and returns hex in lower case with no prefix. */
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

    /** Decodes a 32-byte ABI word that holds an address, padded on the left. */
    private static function addressFromWord(string $word): string
    {
        return '0x'.strtolower(substr($word, 24, 40));
    }

    /** Decodes a 32-byte ABI word as an unsigned integer, and returns a decimal string. */
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
