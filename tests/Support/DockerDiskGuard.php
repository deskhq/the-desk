<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Name a full Docker disk before the suite dies of it.
 *
 * Postgres does not fail gracefully when the disk underneath it fills: the run
 * dies mid-suite with `SQLSTATE[53100]: Disk full: 7 ERROR: could not extend
 * file`, the container crashes with it, and the NEXT run then fails on
 * `could not translate host name "pgsql" to address` — a different and equally
 * misleading message. Neither says "your Docker disk is full", so the first
 * instinct is to go looking for a bug in the code under test (issue #1095).
 *
 * Unlike StaleBundleGuard and ReverbGuard this only warns. Those two guard a
 * suite that cannot pass at all in the state they detect; a disk merely running
 * low usually still gets through, and refusing to run would be the more
 * expensive mistake.
 */
final class DockerDiskGuard
{
    /**
     * Whether this process has already measured the disk.
     */
    private static bool $checked = false;

    /**
     * Free space below which the run is worth warning about, in bytes. Low
     * enough that a healthy machine never sees it, high enough to land before
     * Postgres does — the run that prompted this had 4.7 GiB free on the host
     * and a Docker disk with nothing left at all.
     */
    public const int DEFAULT_FLOOR_BYTES = 1024 * 1024 * 1024;

    /**
     * Environment variable overriding the floor, in MiB.
     */
    public const string FLOOR_VARIABLE = 'TEST_MIN_FREE_DISK_MB';

    /**
     * Warn, once, when the filesystem behind the given path is nearly full.
     *
     * Written to STDERR rather than thrown: a thrown exception would surface as
     * one failure per test, which is the very shape of output this guard exists
     * to replace.
     */
    public static function warnWhenDiskIsLow(string $path): void
    {
        if (self::$checked) {
            return;
        }

        self::$checked = true;

        $warning = self::lowDiskWarning($path);

        if ($warning === null) {
            return;
        }

        fwrite(STDERR, PHP_EOL.$warning.PHP_EOL.PHP_EOL);
    }

    /**
     * The message to show when the filesystem behind the given path is nearly
     * full, or null when it has room — or when its free space cannot be
     * measured at all, which says nothing about the disk either way.
     *
     * Inside the Sail container `/tmp` sits on the container's own writable
     * layer, so this measures the Docker disk rather than the bind-mounted
     * checkout on the host. That is the one that fills.
     */
    public static function lowDiskWarning(string $path, ?int $floorBytes = null): ?string
    {
        $free = self::freeBytes($path);

        if ($free === null || $free >= ($floorBytes ?? self::floorBytes())) {
            return null;
        }

        $megabytes = intdiv($free, 1024 * 1024);

        return <<<MESSAGE
              Only {$megabytes} MiB left on the disk behind {$path}.

              Postgres dies mid-suite when it runs out, with SQLSTATE[53100] "could not
              extend file ... No space left on device", and takes its container with it —
              so the next run fails on an unresolvable `pgsql` host instead. Neither
              message names the disk.

              Inside the container that path is Docker's own disk rather than the
              host's, so reclaim it there:

                  bin/worktree reap      # volumes left behind by removed worktrees
                  docker builder prune   # the build cache
                  docker volume prune    # every volume no container is using
            MESSAGE;
    }

    /**
     * The configured floor in bytes, from the environment when it names a usable
     * one.
     *
     * A value too large to express in bytes falls back to the default rather
     * than being converted: the multiplication would overflow to a float, and
     * this method is declared `int` under strict types, so returning one would
     * abort every test in `setUp()`.
     */
    private static function floorBytes(): int
    {
        $megabytes = getenv(self::FLOOR_VARIABLE);
        $largest = intdiv(PHP_INT_MAX, 1024 * 1024);

        if (! is_string($megabytes) || ! ctype_digit($megabytes) || (int) $megabytes > $largest) {
            return self::DEFAULT_FLOOR_BYTES;
        }

        return (int) $megabytes * 1024 * 1024;
    }

    /**
     * Free space on the filesystem behind the given path, or null when it
     * cannot be measured.
     *
     * An unstattable path is the answer here, not a problem, so the warning
     * `disk_free_space()` raises for one is swallowed by a handler rather than
     * by `@` — PHPUnit's error handler reports suppressed diagnostics anyway,
     * and would turn a missing path into a test warning.
     */
    private static function freeBytes(string $path): ?int
    {
        set_error_handler(static fn (): bool => true);

        try {
            $free = disk_free_space($path);
        } finally {
            restore_error_handler();
        }

        return $free === false ? null : (int) $free;
    }
}
