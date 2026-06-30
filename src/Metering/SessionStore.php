<?php

namespace Square1\Mpp\Metering;

/**
 * Stores prepaid metering sessions and decrements them atomically.
 *
 * The whole point of this interface is `consume()`: it MUST be safe under
 * concurrency so N simultaneous requests can never spend more credits than a
 * session was granted (no oversell).
 */
interface SessionStore
{
    public function create(
        string $scope,
        int $remaining,
        int $ttl,
        ?string $settlementRef = null,
        ?string $payerRef = null,
    ): Session;

    public function find(string $id): ?Session;

    /**
     * Atomically spend one credit, but only if the session exists, is not
     * expired, matches the given scope, and has credits remaining. Returns the
     * updated session on success, or null if nothing was spent.
     */
    public function consume(string $id, string $scope): ?Session;

    public function destroy(string $id): void;
}
