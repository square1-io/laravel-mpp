<?php

namespace Square1\Mpp\Metering\Stores;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Square1\Mpp\Metering\Session;
use Square1\Mpp\Metering\SessionStore;

/**
 * A session store that uses the cache.
 *
 * The count of remaining credits lives in its own key, so that the store can
 * decrement it atomically, for example with Redis DECR.
 *
 * To prevent an oversell under concurrency, the store decrements the count
 * first. When the result is below zero, it returns the credit and rejects the
 * request. Exactly `remaining` callers therefore succeed.
 *
 * The store uses the default cache store of the application, unless the config
 * names another one. An application that uses Redis therefore keeps its sessions
 * in Redis, with no further setup.
 */
class CacheSessionStore implements SessionStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $defaultTtl = 3600,
        private readonly string $prefix = 'mpp:session:',
    ) {}

    public function create(string $scope, int $remaining, int $ttl, ?string $settlementRef = null, ?string $payerRef = null): Session
    {
        $now = CarbonImmutable::now();
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;

        $session = new Session(
            id: 'sess_'.Str::ulid(),
            scope: $scope,
            remaining: $remaining,
            grantedAt: $now,
            expiresAt: $now->addSeconds($ttl),
            settlementRef: $settlementRef,
            payerRef: $payerRef,
        );

        $this->cache->put($this->metaKey($session->id), serialize($session), $ttl);
        $this->cache->put($this->countKey($session->id), $remaining, $ttl);

        return $session;
    }

    public function find(string $id): ?Session
    {
        $session = $this->meta($id);

        if (! $session) {
            return null;
        }

        if ($session->isExpired()) {
            $this->destroy($id);

            return null;
        }

        $count = $this->cache->get($this->countKey($id));

        if ($count === null) {
            return null;
        }

        return $session->withRemaining((int) $count);
    }

    public function consume(string $id, string $scope): ?Session
    {
        $session = $this->meta($id);

        if (! $session || $session->scope !== $scope || $session->isExpired()) {
            return null;
        }

        $remaining = $this->cache->decrement($this->countKey($id));

        if ($remaining === false || $remaining === null) {
            return null;
        }

        if ($remaining < 0) {
            $this->cache->increment($this->countKey($id));

            return null;
        }

        return $session->withRemaining((int) $remaining);
    }

    public function destroy(string $id): void
    {
        $this->cache->forget($this->metaKey($id));
        $this->cache->forget($this->countKey($id));
    }

    private function meta(string $id): ?Session
    {
        $raw = $this->cache->get($this->metaKey($id));

        if (! is_string($raw)) {
            return null;
        }

        $session = unserialize($raw, ['allowed_classes' => [Session::class, CarbonImmutable::class]]);

        return $session instanceof Session ? $session : null;
    }

    private function metaKey(string $id): string
    {
        return $this->prefix.$id.':meta';
    }

    private function countKey(string $id): string
    {
        return $this->prefix.$id.':count';
    }
}
