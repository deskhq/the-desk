<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * The 100% floor used to be enforced only by `composer test`, on whichever
 * machine happened to run it (#582 turned the driver off in CI to save minutes).
 * That assumes every author runs the local gate, and #1102 and #1118 each landed
 * a hole in consecutive merges to prove they do not: a refactor that drops the
 * last caller of a method breaks no test, so nothing goes red until the next
 * person runs the gate on unrelated work. These tests pin the floor to CI, where
 * the cost of a hole is paid by whoever opened it.
 */
function coverageGateJob(string $job): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/tests.yml')['jobs'][$job];
}

/**
 * The `coverage` input the job hands shivammathur/setup-php, which is what
 * decides whether a driver is loaded at all — `none` leaves `--coverage` with
 * nothing to collect.
 */
function coverageGateDriver(string $job): ?string
{
    $setup = collect(coverageGateJob($job)['steps'])
        ->first(static fn (array $step): bool => str_contains($step['uses'] ?? '', 'setup-php'));

    return $setup['with']['coverage'] ?? null;
}

/**
 * The step running a test suite in the given job — `artisan test` in `ci`,
 * `bin/browser-tests` in `browser`. Both spellings are matched so neither job's
 * assertions can pass by matching nothing.
 */
function coverageGateSuiteCommand(string $job): string
{
    return collect(coverageGateJob($job)['steps'])
        ->pluck('run')
        ->filter(static fn (?string $run): bool => str_contains((string) $run, 'artisan test') || str_contains((string) $run, 'browser-tests'))
        ->implode("\n");
}

/**
 * The floor the local gate enforces, so the two cannot drift apart silently.
 */
function coverageGateLocalFloor(): string
{
    $test = implode(' ', json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true)['scripts']['test']);

    preg_match('/--min=\d+/', $test, $matches);

    return $matches[0] ?? '';
}

test('the ci job loads a coverage driver', function (): void {
    expect(coverageGateDriver('ci'))->toBe('pcov', '--coverage collects nothing without a driver');
});

test('the ci job enforces the coverage floor', function (): void {
    expect(coverageGateSuiteCommand('ci'))->not->toBeEmpty('the suite step must be found, or the assertions below pass on nothing')
        ->and(coverageGateSuiteCommand('ci'))->toContain('--coverage')
        ->and(coverageGateSuiteCommand('ci'))->toContain(coverageGateLocalFloor());
});

test('the local gate still names a floor for ci to match', function (): void {
    expect(coverageGateLocalFloor())->toBe('--min=100');
});

/*
 * The browser suite is deliberately outside the gate — it is not declared as a
 * `<testsuite>` in phpunit.xml, and its job must not grow a driver either, or a
 * run of it would start reporting a figure nobody intends to gate on.
 */
test('the browser job stays out of the coverage gate', function (): void {
    expect(coverageGateDriver('browser'))->toBeNull()
        ->and(coverageGateSuiteCommand('browser'))->not->toBeEmpty('the browser runner must be found, or the assertion below passes on nothing')
        ->and(coverageGateSuiteCommand('browser'))->not->toContain('--coverage');
});
