<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Arr;
use Tests\Support\SchemaBootstrapGuard;
use Tests\Support\SchemaBootstrapLock;

/*
 * These tests drive the two globals the guard reads — Paratest's worker token
 * and RefreshDatabase's "already migrated" flag. Both are process-wide, so the
 * worker running this file would carry a stray value into every later test;
 * capture and restore them around each one.
 */
beforeEach(function (): void {
    $this->token = Arr::get($_SERVER, 'TEST_TOKEN');
    $this->migrated = RefreshDatabaseState::$migrated;
});

afterEach(function (): void {
    if ($this->token === null) {
        unset($_SERVER['TEST_TOKEN']);
    } else {
        $_SERVER['TEST_TOKEN'] = $this->token;
    }

    RefreshDatabaseState::$migrated = $this->migrated;
});

/**
 * The exception Postgres raises when it picks a worker's schema transaction as
 * the victim of a deadlock cycle, as Laravel surfaces it.
 */
function postgresDeadlock(): QueryException
{
    return new QueryException(
        'pgsql',
        'alter table "users" add constraint "users_owner_team_id_foreign" foreign key ("owner_team_id") references "teams" ("id")',
        [],
        new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'),
    );
}

/**
 * A lock that records the calls made against it, so a test can assert which
 * mode the guard asked for on each attempt.
 */
function schemaBootstrapLockSpy(): SchemaBootstrapLock
{
    return new class implements SchemaBootstrapLock
    {
        /** @var list<string> */
        public array $calls = [];

        public function acquireShared(): void
        {
            $this->calls[] = 'shared';
        }

        public function acquireExclusive(): void
        {
            $this->calls[] = 'exclusive';
        }

        public function release(): void
        {
            $this->calls[] = 'release';
        }
    };
}

test('a serial run is never guarded, because nothing else is issuing DDL', function (): void {
    unset($_SERVER['TEST_TOKEN']);
    RefreshDatabaseState::$migrated = false;

    expect(SchemaBootstrapGuard::shouldGuard())->toBeFalse();
});

test('a parallel worker is guarded until its schema is built', function (): void {
    $_SERVER['TEST_TOKEN'] = '7';
    RefreshDatabaseState::$migrated = false;

    expect(SchemaBootstrapGuard::shouldGuard())->toBeTrue();
});

test('a parallel worker stops paying for the guard once its schema is built', function (): void {
    $_SERVER['TEST_TOKEN'] = '7';
    RefreshDatabaseState::$migrated = true;

    expect(SchemaBootstrapGuard::shouldGuard())->toBeFalse();
});

test('the bootstrap runs once under a shared lock when nothing deadlocks', function (): void {
    $lock = schemaBootstrapLockSpy();
    $runs = 0;

    SchemaBootstrapGuard::run(function () use (&$runs): void {
        $runs++;
    }, $lock);

    expect($runs)->toBe(1)
        ->and($lock->calls)->toBe(['shared', 'release']);
});

test('a deadlocked bootstrap is retried alone under an exclusive lock', function (): void {
    $lock = schemaBootstrapLockSpy();
    $attempts = 0;

    SchemaBootstrapGuard::run(function () use (&$attempts): void {
        $attempts++;

        if ($attempts === 1) {
            throw postgresDeadlock();
        }
    }, $lock);

    expect($attempts)->toBe(2)
        ->and($lock->calls)->toBe(['shared', 'release', 'exclusive', 'release']);
});

test('a failure that is not a deadlock is not retried', function (): void {
    $lock = schemaBootstrapLockSpy();
    $attempts = 0;

    expect(function () use ($lock, &$attempts): void {
        SchemaBootstrapGuard::run(function () use (&$attempts): void {
            $attempts++;

            throw new RuntimeException('migration is broken');
        }, $lock);
    })->toThrow(RuntimeException::class, 'migration is broken');

    expect($attempts)->toBe(1)
        ->and($lock->calls)->toBe(['shared', 'release']);
});

test('a bootstrap that keeps deadlocking gives up and reports the deadlock', function (): void {
    $lock = schemaBootstrapLockSpy();
    $attempts = 0;

    expect(function () use ($lock, &$attempts): void {
        SchemaBootstrapGuard::run(function () use (&$attempts): void {
            $attempts++;

            throw postgresDeadlock();
        }, $lock);
    })->toThrow(QueryException::class, 'deadlock detected');

    expect($attempts)->toBe(SchemaBootstrapGuard::ATTEMPTS)
        ->and($lock->calls)->toBe([
            'shared', 'release',
            'exclusive', 'release',
            'exclusive', 'release',
        ]);
});
