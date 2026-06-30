<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Square1\Mpp\Metering\SessionStore;
use Square1\Mpp\Metering\Stores\CacheSessionStore;
use Square1\Mpp\Metering\Stores\DatabaseSessionStore;

function makeStore(string $driver): SessionStore
{
    return $driver === 'database'
        ? new DatabaseSessionStore(app('db')->connection(), 'mpp_sessions', 3600)
        : new CacheSessionStore(app('cache')->store(), 3600);
}

dataset('stores', ['cache', 'database']);

beforeEach(function () {
    Schema::dropIfExists('mpp_sessions');
    Schema::create('mpp_sessions', function (Blueprint $table) {
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
    CarbonImmutable::setTestNow();
    Schema::dropIfExists('mpp_sessions');
});

it('creates and finds a session', function (string $driver) {
    $store = makeStore($driver);
    $created = $store->create('report.basic', 10, 3600, 'pi_123');

    expect($created->id)->toStartWith('sess_');
    expect($store->find($created->id)->remaining)->toBe(10);
})->with('stores');

it('decrements by one on consume', function (string $driver) {
    $store = makeStore($driver);
    $session = $store->create('report.basic', 10, 3600);

    expect($store->consume($session->id, 'report.basic')->remaining)->toBe(9)
        ->and($store->find($session->id)->remaining)->toBe(9);
})->with('stores');

it('rejects a scope mismatch without spending', function (string $driver) {
    $store = makeStore($driver);
    $session = $store->create('report.basic', 10, 3600);

    expect($store->consume($session->id, 'clip.latest'))->toBeNull()
        ->and($store->find($session->id)->remaining)->toBe(10);
})->with('stores');

it('cannot be overspent', function (string $driver) {
    $store = makeStore($driver);
    $session = $store->create('report.basic', 1, 3600);

    expect($store->consume($session->id, 'report.basic')->remaining)->toBe(0)
        ->and($store->consume($session->id, 'report.basic'))->toBeNull();
})->with('stores');

it('hands out exactly the granted number of credits', function (string $driver) {
    $store = makeStore($driver);
    $session = $store->create('report.basic', 10, 3600);

    $successes = 0;
    for ($i = 0; $i < 25; $i++) {
        if ($store->consume($session->id, 'report.basic') !== null) {
            $successes++;
        }
    }

    expect($successes)->toBe(10)->and($store->find($session->id)->remaining)->toBe(0);
})->with('stores');

it('does not consume an expired session', function (string $driver) {
    CarbonImmutable::setTestNow('2026-06-18T12:00:00Z');
    $store = makeStore($driver);
    $session = $store->create('report.basic', 10, 60);

    CarbonImmutable::setTestNow('2026-06-18T12:05:00Z');

    expect($store->consume($session->id, 'report.basic'))->toBeNull()
        ->and($store->find($session->id))->toBeNull();
})->with('stores');

it('destroys a session', function (string $driver) {
    $store = makeStore($driver);
    $session = $store->create('report.basic', 10, 3600);
    $store->destroy($session->id);

    expect($store->find($session->id))->toBeNull();
})->with('stores');
