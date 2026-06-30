<?php

namespace Square1\Mpp\Protocol\Tempo;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Square1\Mpp\Protocol\ChallengeStore;

/**
 * Persists issued mppx-dialect (tempo) challenge state so a paid retry can be
 * matched to the challenge we issued, and so a challenge is single-use (burned
 * after it settles). Mirrors {@see ChallengeStore} for the
 * tempo state object.
 */
final class TempoChallengeStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttl = 300,
        private readonly string $prefix = 'mpp:tempo:challenge:',
    ) {}

    public function put(TempoChallengeState $state): void
    {
        $this->cache->put($this->key($state->id), serialize($state), $this->ttl);
    }

    public function find(string $id): ?TempoChallengeState
    {
        $raw = $this->cache->get($this->key($id));

        if (! is_string($raw)) {
            return null;
        }

        $state = unserialize($raw, ['allowed_classes' => [TempoChallengeState::class, CarbonImmutable::class]]);

        return $state instanceof TempoChallengeState ? $state : null;
    }

    public function exists(string $id): bool
    {
        return $this->cache->has($this->key($id));
    }

    /**
     * Burn a challenge so it cannot settle twice. Returns true if it was present.
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
