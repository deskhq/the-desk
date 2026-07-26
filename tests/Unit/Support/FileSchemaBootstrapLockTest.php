<?php

declare(strict_types=1);

use Tests\Support\FileSchemaBootstrapLock;

/**
 * Probe the lock file the way a rival Paratest worker would: from its own file
 * handle, without blocking. Returns whether the mode could be taken.
 */
function canTakeLock(string $path, int $mode): bool
{
    $handle = fopen($path, 'c');
    $taken = flock($handle, $mode | LOCK_NB);

    if ($taken) {
        flock($handle, LOCK_UN);
    }

    fclose($handle);

    return $taken;
}

beforeEach(function (): void {
    $this->lockPath = tempnam(sys_get_temp_dir(), 'schema-bootstrap-test-');
});

afterEach(function (): void {
    @unlink($this->lockPath);
});

test('workers bootstrapping in parallel all hold the lock at once', function (): void {
    $first = new FileSchemaBootstrapLock($this->lockPath);
    $second = new FileSchemaBootstrapLock($this->lockPath);

    $first->acquireShared();
    $second->acquireShared();

    expect(canTakeLock($this->lockPath, LOCK_SH))->toBeTrue()
        ->and(canTakeLock($this->lockPath, LOCK_EX))->toBeFalse();

    $first->release();
    $second->release();
});

test('a serialized retry keeps every other worker out until it is done', function (): void {
    $lock = new FileSchemaBootstrapLock($this->lockPath);

    $lock->acquireExclusive();

    expect(canTakeLock($this->lockPath, LOCK_SH))->toBeFalse()
        ->and(canTakeLock($this->lockPath, LOCK_EX))->toBeFalse();

    $lock->release();

    expect(canTakeLock($this->lockPath, LOCK_EX))->toBeTrue();
});

test('releasing a lock that was never taken is harmless', function (): void {
    $lock = new FileSchemaBootstrapLock($this->lockPath);

    $lock->release();

    expect(canTakeLock($this->lockPath, LOCK_EX))->toBeTrue();
});

test('every worker of a run shares one lock file', function (): void {
    expect(FileSchemaBootstrapLock::forRun())->toEqual(FileSchemaBootstrapLock::forRun());
});
