<?php

namespace Square1\Mpp\Support\Evm;

/**
 * MPP attribution memo encoding/verification for TIP-20 `transferWithMemo`,
 * byte-compatible with the mppx reference (`dist/tempo/Attribution.js`).
 *
 * When the server sets no explicit memo, the client auto-generates this 32-byte
 * attribution memo and embeds it in the transfer call so the payment is bound to
 * the challenge that requested it. The layout (32 bytes):
 *
 *   | 0..3   | 4  | TAG       = keccak256("mpp")[0..3]
 *   | 4      | 1  | version   = 0x01
 *   | 5..14  | 10 | serverId  = keccak256(realm)[0..9]
 *   | 15..24 | 10 | clientId  = keccak256(clientId)[0..9] or zeros (anonymous)
 *   | 25..31 | 7  | nonce     = keccak256(challengeId)[0..6]
 *
 * The server verifies the TAG + version, that the server fingerprint matches the
 * realm it issued, and that the nonce matches keccak256(challengeId)[0..6]. That
 * makes the binding load-bearing: a transfer carrying a memo for one challenge
 * id cannot satisfy another, even at the same price/recipient.
 */
final class Attribution
{
    private const VERSION = 0x01;

    /** First 4 bytes of keccak256("mpp"). */
    public static function tag(): string
    {
        return '0x'.bin2hex(substr(Keccak::hash('mpp'), 0, 4));
    }

    /** Whether a 0x-prefixed bytes32 memo carries the MPP tag + version byte. */
    public static function isMppMemo(string $memo): bool
    {
        $memo = self::normalize($memo);

        if (strlen($memo) !== 66) {
            return false;
        }

        $memoTag = strtolower(substr($memo, 0, 10));
        $version = (int) hexdec(substr($memo, 10, 2));

        return $memoTag === strtolower(self::tag()) && $version === self::VERSION;
    }

    /** Verify the memo's server fingerprint (bytes 5..14) equals keccak256(realm)[0..9]. */
    public static function verifyServer(string $memo, string $realm): bool
    {
        $memo = self::normalize($memo);

        if (! self::isMppMemo($memo)) {
            return false;
        }

        $memoServer = strtolower(substr($memo, 12, 20));
        $expected = bin2hex(substr(Keccak::hash($realm), 0, 10));

        return $memoServer === $expected;
    }

    /** Verify the memo's nonce (bytes 25..31) equals keccak256(challengeId)[0..6]. */
    public static function verifyChallengeBinding(string $memo, string $challengeId): bool
    {
        $memo = self::normalize($memo);

        if (! self::isMppMemo($memo)) {
            return false;
        }

        $memoNonce = strtolower(substr($memo, 52, 14));
        $expected = bin2hex(substr(Keccak::hash($challengeId), 0, 7));

        return $memoNonce === $expected;
    }

    private static function normalize(string $memo): string
    {
        if (str_starts_with($memo, '0x') || str_starts_with($memo, '0X')) {
            return '0x'.strtolower(substr($memo, 2));
        }

        return '0x'.strtolower($memo);
    }
}
