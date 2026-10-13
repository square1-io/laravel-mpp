<?php

namespace Square1\Mpp\Protocol\Tempo;

use Square1\Mpp\Support\Evm\Keccak;

/**
 * The MPP attribution memo that mppx writes into a TIP-20 `transferWithMemo`.
 *
 * Since mppx 0.11.0, the mppx client ignores a `methodDetails.memo` that the
 * server advertises. It always writes its own bytes32 attribution memo instead:
 *
 *   | bytes  | len | value                                      |
 *   |--------|-----|--------------------------------------------|
 *   | 0..3   | 4   | keccak256("mpp")[0..3], the MPP tag        |
 *   | 4      | 1   | the version, 0x01                          |
 *   | 5..14  | 10  | keccak256(serverId)[0..9]                  |
 *   | 15..24 | 10  | keccak256(clientId)[0..9], or zeros        |
 *   | 25..31 | 7   | keccak256(challengeId)[0..6], the nonce    |
 *
 * The serverId is the realm of the challenge. The nonce is derived from the
 * challenge id, so a memo binds the transfer to one challenge, as the random
 * memo that this package advertises does. The client chooses the clientId
 * freely, and this class does not check it.
 *
 * draft-tempo-charge-00 does not define this format. The package accepts it
 * for compatibility with the mppx client. The layout follows
 * `mppx/dist/tempo/Attribution.js`.
 */
final class AttributionMemo
{
    /** The MPP tag, keccak256("mpp")[0..3], followed by the version byte 0x01. */
    public const PREFIX = 'ef1ed712'.'01';

    /**
     * Returns true when the memo is an attribution memo for this server and
     * this challenge.
     *
     * The memo must be bytes32 as 0x-prefixed lower-case hex. The method checks
     * the tag, the version, the server fingerprint and the challenge nonce. It
     * ignores the client fingerprint.
     */
    public static function matches(string $memo, string $challengeId, string $serverId): bool
    {
        if (strlen($memo) !== 66 || ! str_starts_with($memo, '0x'.self::PREFIX)) {
            return false;
        }

        return hash_equals(self::keccakPrefix($serverId, 10), substr($memo, 12, 20))
            && hash_equals(self::keccakPrefix($challengeId, 7), substr($memo, 52, 14));
    }

    /** The first $bytes bytes of keccak256($value), as lower-case hex. */
    private static function keccakPrefix(string $value, int $bytes): string
    {
        return substr(Keccak::hashHex($value), 2, $bytes * 2);
    }
}
