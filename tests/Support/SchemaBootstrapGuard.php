<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Throwable;

final class SchemaBootstrapGuard
{
    /**
     * How many times the bootstrap is attempted before the deadlock is fatal.
     * The first attempt runs shared; every later one runs exclusive.
     */
    public const int ATTEMPTS = 3;

    /**
     * Whether this test's setup still has schema work worth guarding.
     *
     * Only a parallel run has rival workers issuing DDL, and only until this
     * worker has created and migrated its own database — from then on its
     * setup touches no catalog and there is nothing left to deadlock over.
     */
    public static function shouldGuard(): bool
    {
        // The same signal Illuminate\Testing\ParallelTesting::token() reads,
        // taken directly because the guard runs before the application (and
        // therefore the facade root) exists.
        $runningInParallel = isset($_SERVER['TEST_TOKEN']);

        return $runningInParallel && ! RefreshDatabaseState::$migrated;
    }

    /**
     * Run the per-worker schema bootstrap under the given lock, retrying it
     * alone if Postgres picks this worker as a deadlock victim.
     */
    public static function run(Closure $bootstrap, SchemaBootstrapLock $lock): void
    {
        $detector = new ConcurrencyErrorDetector;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if ($attempt === 1) {
                $lock->acquireShared();
            } else {
                $lock->acquireExclusive();
            }

            try {
                $bootstrap();

                return;
            } catch (Throwable $e) {
                throw_if($attempt === self::ATTEMPTS || ! $detector->causedByConcurrencyError($e), $e);
            } finally {
                $lock->release();
            }
        }
    }
}
