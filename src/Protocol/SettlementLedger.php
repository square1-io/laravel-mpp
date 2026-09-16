<?php

namespace Square1\Mpp\Protocol;

use Illuminate\Contracts\Cache\Repository;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Remembers a settled challenge for long enough to make a retry idempotent.
 *
 * Settlement burns the challenge, because a challenge is single-use. Without
 * this ledger, a paid request whose 200 response was lost would retry, find no
 * challenge, receive a fresh 402, and pay a SECOND time for one resource.
 *
 * The ledger records the settlement outcome under the challenge id. The binding
 * nonce makes that id unique per mint. A retry within `ttl` replays the original
 * receipt, and the client does not pay again.
 *
 * The TTL is a window and not a permanent record. It is long enough to cover the
 * retries that a client makes in practice, and the default is five minutes, in
 * `mpp.settlement_replay_ttl`. It is short enough to keep the store from growing
 * without a limit.
 *
 * The TTL is deliberately separate from the challenge TTL. A challenge is live
 * only until its first use. Its receipt must survive the retry window, and that
 * window opens AFTER the challenge settles.
 */
class SettlementLedger
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttl = 300,
        private readonly string $prefix = 'mpp:settled:',
    ) {}

    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<Cookie>  $cookies
     */
    public function record(
        string $challengeId,
        string $fingerprint,
        ?string $sessionId,
        int $status,
        string $content,
        array $headers,
        array $cookies,
    ): void {
        $this->cache->put(
            $this->key($challengeId),
            serialize(new SettlementRecord($fingerprint, $sessionId, $status, $content, $headers, $cookies)),
            $this->ttl,
        );
    }

    public function find(string $challengeId): ?SettlementRecord
    {
        $raw = $this->cache->get($this->key($challengeId));

        if (! is_string($raw)) {
            return null;
        }

        $record = unserialize($raw, [
            'allowed_classes' => [SettlementRecord::class, Cookie::class],
        ]);

        if (! $record instanceof SettlementRecord) {
            return null;
        }

        // A record that the server wrote before the current fields existed
        // unserialises with those properties uninitialised. This happens when a
        // deploy lands inside the replay window. Without the fingerprint to
        // authorise the replay, and without the stored response to serve, a
        // replay is not safe. Treat such a record as absent, and let the client
        // take a fresh challenge.
        return isset($record->fingerprint, $record->status, $record->content) ? $record : null;
    }

    private function key(string $id): string
    {
        return $this->prefix.$id;
    }
}
