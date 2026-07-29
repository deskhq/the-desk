<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A cross-process reader/writer lock guarding the per-worker schema bootstrap.
 *
 * Shared mode is the fast path: every Paratest worker holds it at once, so the
 * bootstrap stays as parallel as it is today. Exclusive mode waits for every
 * shared holder to let go, which is what makes a serialized retry genuinely
 * alone in the Postgres cluster.
 */
interface SchemaBootstrapLock
{
    public function acquireShared(): void;

    public function acquireExclusive(): void;

    public function release(): void;
}
