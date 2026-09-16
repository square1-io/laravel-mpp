<?php

namespace Square1\Mpp\Metering\Stores;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Square1\Mpp\Metering\Session;
use Square1\Mpp\Metering\SessionStore;

/**
 * A session store that uses the database. `consume()` is one guarded UPDATE:
 *
 *   UPDATE mpp_sessions SET remaining = remaining - 1
 *   WHERE id = ? AND scope = ? AND remaining > 0 AND expires_at > now
 *
 * One UPDATE statement is atomic in every RDBMS. The count of affected rows,
 * which is 1 or 0, therefore states whether this caller received a credit. The
 * store needs no row lock, and it does not oversell under heavy concurrency.
 *
 * The store uses the default database connection, unless the config names
 * another one.
 */
class DatabaseSessionStore implements SessionStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'mpp_sessions',
        private readonly int $defaultTtl = 3600,
    ) {}

    public function create(string $scope, int $remaining, int $ttl, ?string $settlementRef = null, ?string $payerRef = null): Session
    {
        $now = CarbonImmutable::now();
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $expiresAt = $now->addSeconds($ttl);

        $session = new Session(
            id: 'sess_'.Str::ulid(),
            scope: $scope,
            remaining: $remaining,
            grantedAt: $now,
            expiresAt: $expiresAt,
            settlementRef: $settlementRef,
            payerRef: $payerRef,
        );

        $this->connection->table($this->table)->insert([
            'id' => $session->id,
            'scope' => $scope,
            'remaining' => $remaining,
            'settlement_ref' => $settlementRef,
            'payer_ref' => $payerRef,
            'granted_at' => $now,
            'expires_at' => $expiresAt,
        ]);

        return $session;
    }

    public function find(string $id): ?Session
    {
        $row = $this->connection->table($this->table)->where('id', $id)->first();

        if (! $row) {
            return null;
        }

        $session = $this->hydrate($row);

        if ($session->isExpired()) {
            $this->destroy($id);

            return null;
        }

        return $session;
    }

    public function consume(string $id, string $scope): ?Session
    {
        $affected = $this->connection->table($this->table)
            ->where('id', $id)
            ->where('scope', $scope)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->decrement('remaining');

        if ($affected === 0) {
            return null;
        }

        $row = $this->connection->table($this->table)->where('id', $id)->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function destroy(string $id): void
    {
        $this->connection->table($this->table)->where('id', $id)->delete();
    }

    private function hydrate(object $row): Session
    {
        return new Session(
            id: $row->id,
            scope: $row->scope,
            remaining: (int) $row->remaining,
            grantedAt: CarbonImmutable::parse($row->granted_at),
            expiresAt: CarbonImmutable::parse($row->expires_at),
            settlementRef: $row->settlement_ref,
            payerRef: $row->payer_ref,
        );
    }
}
