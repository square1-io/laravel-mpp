<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Square1\Mpp\Metering\Stores\DatabaseSessionStore;

/**
 * Proves the no-oversell guarantee with real OS-level concurrency: many forked
 * processes hammer a single session at once, and we assert no more credits are
 * handed out than were granted. The guarded atomic UPDATE is what makes this
 * safe. Skipped where pcntl is unavailable.
 */
function mppRaceFor(int $grants, int $workers, string $markerDir): int
{
    (new DatabaseSessionStore(app('db')->connection('mpp_concurrency'), 'mpp_sessions', 3600))
        ->create('report.basic', $grants, 3600);
    $session = app('db')->connection('mpp_concurrency')->table('mpp_sessions')->first();

    app('db')->purge('mpp_concurrency');

    $pids = [];
    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            mppConcurrencyWorker($session->id, $markerDir);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    return count(glob($markerDir.'/*') ?: []);
}

function mppConcurrencyWorker(string $sessionId, string $markerDir): void
{
    try {
        app('db')->purge('mpp_concurrency');
        $connection = app('db')->connection('mpp_concurrency');
        $connection->statement('PRAGMA busy_timeout = 5000');
        $store = new DatabaseSessionStore($connection, 'mpp_sessions', 3600);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            try {
                if ($store->consume($sessionId, 'report.basic') !== null) {
                    file_put_contents($markerDir.'/'.getmypid(), '1');
                }
                break;
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'locked') || str_contains($e->getMessage(), 'busy')) {
                    usleep(5000);

                    continue;
                }
                throw $e;
            }
        }
    } catch (Throwable $e) {
        // A crashing child must not be counted as a success.
    }
}

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the concurrency test.');
    }

    $this->dbFile = tempnam(sys_get_temp_dir(), 'mpp_conc_').'.sqlite';
    $this->markerDir = tempnam(sys_get_temp_dir(), 'mpp_marks_');
    @unlink($this->markerDir);
    mkdir($this->markerDir, 0700, true);
    touch($this->dbFile);

    config(['database.connections.mpp_concurrency' => [
        'driver' => 'sqlite',
        'database' => $this->dbFile,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    app('db')->connection('mpp_concurrency')->getSchemaBuilder()->create('mpp_sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('scope')->index();
        $table->unsignedInteger('remaining');
        $table->string('settlement_ref')->nullable();
        $table->string('payer_ref')->nullable();
        $table->timestamp('granted_at');
        $table->timestamp('expires_at')->index();
    });
});

afterEach(function () {
    if (isset($this->markerDir)) {
        @array_map('unlink', glob($this->markerDir.'/*') ?: []);
        @rmdir($this->markerDir);
    }
    if (isset($this->dbFile)) {
        @unlink($this->dbFile);
    }
});

test('a single credit is never oversold', function () {
    expect(mppRaceFor(grants: 1, workers: 20, markerDir: $this->markerDir))->toBe(1);
})->group('concurrency');

test('exactly the granted credits are handed out', function () {
    expect(mppRaceFor(grants: 5, workers: 30, markerDir: $this->markerDir))->toBe(5);
})->group('concurrency');
