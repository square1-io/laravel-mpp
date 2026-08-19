<?php

namespace Square1\Mpp\Protocol;

use Illuminate\Contracts\Cache\Repository;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Remembers a settled challenge just long enough to make a retry idempotent.
 *
 * Settlement burns the challenge (single-use), so without this a paid request
 * whose 200 was lost in transit would retry, find its challenge gone, be met
 * with a fresh 402, and pay a SECOND time for one resource. The ledger records
 * the settlement outcome keyed by challenge id (unique per mint thanks to the
 * binding nonce); a retry within `ttl` replays the original receipt instead of
 * charging again.
 *
 * TTL is a window, not forever: long enough to cover realistic client retries
 * (default 5 min, `mpp.settlement_replay_ttl`), short enough that the store does
 * not grow without bound. It is deliberately independent of the challenge TTL —
 * a challenge is live only until first use, but its receipt must survive the
 * retry window that opens AFTER it settles.
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

        // A record written before the current fields existed (a deploy landing
        // inside the replay window) unserialises with those properties
        // uninitialised. Without the fingerprint to authorise the replay, or the
        // stored response to serve, it is not safe to replay: treat it as absent
        // and let the client take a fresh challenge.
        return isset($record->fingerprint, $record->status, $record->content) ? $record : null;
    }

    private function key(string $id): string
    {
        return $this->prefix.$id;
    }
}
