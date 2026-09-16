<?php

namespace Square1\Mpp\Metering;

/**
 * Stores prepaid metering sessions, and decrements them atomically.
 *
 * `consume()` is the reason for this interface. It MUST be safe under
 * concurrency. N simultaneous requests can then never spend more credits than
 * the server granted to a session, so the store never oversells.
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
     * Spends one credit atomically.
     *
     * The method spends a credit only when the session exists, has not expired,
     * matches the given scope, and has credits left. It returns the updated
     * session on success, and null when it spent nothing.
     */
    public function consume(string $id, string $scope): ?Session;

    public function destroy(string $id): void;
}
