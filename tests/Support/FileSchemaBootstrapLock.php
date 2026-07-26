<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A {@see SchemaBootstrapLock} backed by `flock()` on a file every Paratest
 * worker of the run opens. Paratest workers are always processes on one
 * machine, so a file lock reaches all of them without needing a database
 * connection — which matters because the guard runs before the application
 * (and therefore the database config) has booted.
 */
final class FileSchemaBootstrapLock implements SchemaBootstrapLock
{
    /** @var resource|null */
    private $handle;

    public function __construct(private readonly string $path) {}

    /**
     * The lock every worker of this checkout's run shares. It lives in the
     * temporary directory rather than the project tree so that `flock()` runs
     * against a real Linux filesystem and not a bind-mounted host volume.
     */
    public static function forRun(): self
    {
        return new self(sys_get_temp_dir().'/schema-bootstrap-'.md5(dirname(__DIR__, 2)).'.lock');
    }

    public function acquireShared(): void
    {
        $this->acquire(LOCK_SH);
    }

    public function acquireExclusive(): void
    {
        $this->acquire(LOCK_EX);
    }

    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }

    private function acquire(int $mode): void
    {
        $handle = fopen($this->path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the schema bootstrap lock at [{$this->path}].");
        }

        $this->handle = $handle;

        flock($handle, $mode);
    }
}
