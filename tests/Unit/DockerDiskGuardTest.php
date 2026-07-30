<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\DockerDiskGuard;

/**
 * A full Docker disk does not announce itself. Postgres dies mid-suite with
 * `SQLSTATE[53100]: Disk full`, the container crashes with it, and the next run
 * then fails on `could not translate host name "pgsql" to address` — so the
 * first instinct is to go looking for a bug in the code under test (issue
 * #1095). These tests pin the preflight that names the real cause instead.
 */
// Captured before any test overrides it and restored after each one, rather
// than unset: the variable is a developer's to set, and wiping it would leak out
// of this file into the rest of the worker's run.
$originalFloor = getenv(DockerDiskGuard::FLOOR_VARIABLE);

afterEach(function () use ($originalFloor): void {
    putenv($originalFloor === false
        ? DockerDiskGuard::FLOOR_VARIABLE
        : DockerDiskGuard::FLOOR_VARIABLE.'='.$originalFloor);
});

/**
 * Drive `warnWhenDiskIsLow()` in its own process, so the memo under test is not
 * one this run has already spent.
 *
 * @param  list<string>  $paths  one call per path, in order
 */
function runDiskGuard(array $paths, ?string $floorMegabytes = null): Process
{
    $calls = implode('', array_map(
        static fn (string $path): string => sprintf(
            'Tests\Support\DockerDiskGuard::warnWhenDiskIsLow(%s);',
            var_export($path, true),
        ),
        $paths,
    ));

    $process = new Process(
        [PHP_BINARY, '-r', sprintf(
            'require %s; %s echo "the suite ran";',
            var_export(dirname(__DIR__).'/Support/DockerDiskGuard.php', true),
            $calls,
        )],
        env: $floorMegabytes === null ? [] : [DockerDiskGuard::FLOOR_VARIABLE => $floorMegabytes],
    );
    $process->run();

    return $process;
}

it('stays quiet while there is room left on the disk', function (): void {
    expect(DockerDiskGuard::lowDiskWarning(sys_get_temp_dir(), floorBytes: 0))->toBeNull();
});

it('names the disk, the error it causes and how to reclaim space', function (): void {
    expect(DockerDiskGuard::lowDiskWarning(sys_get_temp_dir(), floorBytes: PHP_INT_MAX))
        ->toContain('Docker')
        ->toContain('SQLSTATE[53100]')
        ->toContain(sys_get_temp_dir())
        ->toContain('bin/worktree reap')
        ->toContain('docker builder prune')
        ->toContain('docker volume prune');
});

it('reports the free space it measured, so the margin is visible', function (): void {
    $free = (int) disk_free_space(sys_get_temp_dir());

    expect(DockerDiskGuard::lowDiskWarning(sys_get_temp_dir(), floorBytes: PHP_INT_MAX))
        ->toContain((string) intdiv($free, 1024 * 1024));
});

it('takes its floor from the environment, in MiB', function (): void {
    putenv(DockerDiskGuard::FLOOR_VARIABLE.'=999999999');

    expect(DockerDiskGuard::lowDiskWarning(sys_get_temp_dir()))->toContain('SQLSTATE[53100]');

    putenv(DockerDiskGuard::FLOOR_VARIABLE.'=0');

    expect(DockerDiskGuard::lowDiskWarning(sys_get_temp_dir()))->toBeNull();
});

it('falls back to its default floor for a value it cannot use', function (string $floor): void {
    putenv(DockerDiskGuard::FLOOR_VARIABLE.'='.$floor);

    // The default is a gigabyte, and the temp disk this runs on has more; a
    // value that was honoured instead would warn.
    expect(DockerDiskGuard::lowDiskWarning(sys_get_temp_dir()))->toBeNull();
})->with([
    'not a number' => 'plenty',
    'negative' => '-1',
    'fractional' => '1.5',
    // Larger than PHP_INT_MAX bytes: multiplying it out would overflow to a
    // float and, under strict types, throw on the way back.
    'beyond what bytes can express' => '99999999999999999999',
]);

// A path it cannot stat says nothing about the disk, and a guard that cannot
// measure has nothing to warn about.
it('says nothing when the free space cannot be measured', function (): void {
    expect(DockerDiskGuard::lowDiskWarning('/no/such/path/'.bin2hex(random_bytes(6)), floorBytes: PHP_INT_MAX))
        ->toBeNull();
});

// The user's call (#1095): a full disk is worth naming, but not worth refusing
// to run over — unlike StaleBundleGuard and ReverbGuard, which guard a suite
// that cannot pass at all in the state they detect.
it('warns loudly but lets the run proceed', function (): void {
    $process = runDiskGuard([sys_get_temp_dir()], floorMegabytes: '999999999');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('the suite ran')
        ->and($process->getErrorOutput())->toContain('SQLSTATE[53100]');
});

it('warns once per process rather than once per test', function (): void {
    $process = runDiskGuard([sys_get_temp_dir(), sys_get_temp_dir()], floorMegabytes: '999999999');

    expect(substr_count($process->getErrorOutput(), 'SQLSTATE[53100]'))->toBe(1);
});

it('keeps quiet entirely when the disk is fine', function (): void {
    $process = runDiskGuard([sys_get_temp_dir()], floorMegabytes: '0');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('');
});

// Wired into the shared test case rather than into one suite's bootstrap: the
// run that filled the disk was the PHP gate, not the browser suite.
it('runs from the test case every database-backed test extends', function (): void {
    expect((string) file_get_contents(dirname(__DIR__).'/TestCase.php'))
        ->toContain('DockerDiskGuard::warnWhenDiskIsLow(');
});
