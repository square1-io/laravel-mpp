<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Stores minted challenges.
 *
 * The package matches a paid retry to its challenge through this store. It also
 * burns a challenge here after the challenge settles, because a challenge is
 * single-use.
 */
class ChallengeStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttl = 300,
        private readonly string $prefix = 'mpp:challenge:',
    ) {}

    public function put(Challenge $challenge): void
    {
        $this->cache->put($this->key($challenge->id), serialize($challenge), $this->ttl);
    }

    public function find(string $id): ?Challenge
    {
        $raw = $this->cache->get($this->key($id));

        if (! is_string($raw)) {
            return null;
        }

        $challenge = unserialize($raw, ['allowed_classes' => [Challenge::class, CarbonImmutable::class]]);

        return $challenge instanceof Challenge ? $challenge : null;
    }

    public function exists(string $id): bool
    {
        return $this->cache->has($this->key($id));
    }

    /**
     * Burns a challenge, so that it cannot settle twice.
     *
     * The method returns true when the challenge was present, and is now gone. It
     * returns false when the server had already burned the challenge, or when the
     * challenge had expired.
     */
    public function burn(string $id): bool
    {
        $key = $this->key($id);

        if (! $this->cache->has($key)) {
            return false;
        }

        $this->cache->forget($key);

        return true;
    }

    private function key(string $id): string
    {
        return $this->prefix.$id;
    }
}
