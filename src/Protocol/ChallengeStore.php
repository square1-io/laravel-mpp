<?php

namespace Square1\Mpp\Protocol;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Persists minted challenges so a paid retry can be matched to its challenge,
 * and so a challenge can be burned (single-use) after it settles.
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

        $challenge = unserialize($raw, ['allowed_classes' => [Challenge::class, ChallengeOffer::class, CarbonImmutable::class]]);

        return $challenge instanceof Challenge ? $challenge : null;
    }

    public function exists(string $id): bool
    {
        return $this->cache->has($this->key($id));
    }

    /**
     * Burn a challenge so it cannot be settled twice. Returns true if it was
     * present (and is now gone), false if it had already been burned/expired.
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
