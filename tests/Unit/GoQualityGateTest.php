<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * The unfurl no longer happens in `app/`, so the 100% coverage gate cannot see
 * it. `services/unfurler` has its own suite and its own floor, and this file is
 * what keeps that floor visible from inside the gate people actually run.
 *
 * The floor is 90%, deliberately below the PHP suite's 100%. That is a different
 * number for a different language rather than a lower standard applied by
 * accident: Laravel gives you a seam for everything, and Go does not without
 * contorted interfaces around `io` and `net` error branches nothing can trigger.
 * Writing the number down in two places and asserting they agree is what stops
 * it drifting downwards one convenient percent at a time. See ADR-0016.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * The floor, as the rest of the repository must spell it.
 */
const GO_COVERAGE_FLOOR = 90.0;

$workflow = fn (): array => Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/go.yml');

/**
 * @return array<int, array<string, mixed>>
 */
function goJobSteps(callable $workflow): array
{
    /** @var array<int, array<string, mixed>> $steps */
    $steps = $workflow()['jobs']['go']['steps'];

    return $steps;
}

function goJobRuns(callable $workflow, string $needle): bool
{
    foreach (goJobSteps($workflow) as $step) {
        if (str_contains((string) ($step['run'] ?? ''), $needle)) {
            return true;
        }
    }

    return false;
}

test('CI runs the Go suite', function () use ($workflow): void {
    expect(goJobRuns($workflow, 'go test ./...'))->toBeTrue();
});

/**
 * The service fans a batch out across goroutines writing into one shared results
 * slice. A data race there passes every functional test there is, so the race
 * detector is not optional decoration.
 */
test('CI runs the Go suite with the race detector', function () use ($workflow): void {
    expect(goJobRuns($workflow, '-race'))->toBeTrue();
});

test('CI enforces the Go coverage floor, at the number written down here', function () use ($workflow): void {
    expect(goJobRuns($workflow, (string) GO_COVERAGE_FLOOR))->toBeTrue(
        'the go workflow no longer enforces '.GO_COVERAGE_FLOOR.'%',
    );
});

/**
 * Counting a helper exercised from a sibling package's test. Without
 * `-coverpkg`, the shared vocabulary package reads 0% while every test in the
 * service uses it, and the understated total makes the floor meaningless.
 */
test('the Go coverage figure counts cross-package use', function () use ($workflow): void {
    expect(goJobRuns($workflow, '-coverpkg'))->toBeTrue();
});

/**
 * The local script and CI have to agree, or the gate that is convenient to run
 * stops being the gate that decides.
 */
test('the local Go gate enforces the same floor as CI', function () use ($sourceRoot): void {
    $script = (string) file_get_contents($sourceRoot.'/bin/go-test');

    expect(str_contains($script, 'readonly FLOOR='.GO_COVERAGE_FLOOR))->toBeTrue()
        ->and(str_contains($script, '-race'))->toBeTrue()
        ->and(str_contains($script, '-coverpkg'))->toBeTrue();
});

/**
 * `composer ci:check` is the one command that claims to run everything. It has
 * to mean it.
 *
 * `composer test` deliberately does *not* include the Go suite: it is the fast
 * pre-push PHP loop, and a Docker pull in the middle of it would change what
 * that command is for.
 */
test('the full check runs the Go suite and the fast PHP loop does not', function () use ($sourceRoot): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode((string) file_get_contents($sourceRoot.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['ci:check'])->toContain('@test:go')
        ->and($composer['scripts']['test'])->not->toContain('@test:go')
        ->and(implode(' ', $composer['scripts']['test:go']))->toContain('bin/go-test');
});

/**
 * The binary in the image and the binary the suite proves must be built by the
 * same toolchain. A Dockerfile pinned to one Go version while CI tests another
 * is a class of bug that only shows up in production.
 */
test('the image and the test toolchain are pinned to the same Go version', function () use ($sourceRoot): void {
    $dockerfile = (string) file_get_contents($sourceRoot.'/Dockerfile');
    $goMod = (string) file_get_contents($sourceRoot.'/services/unfurler/go.mod');

    expect(preg_match('/^ARG GO_VERSION=(\d+\.\d+)$/m', $dockerfile, $image))->toBe(1)
        ->and(preg_match('/^go (\d+\.\d+)/m', $goMod, $module))->toBe(1)
        ->and($image[1])->toBe($module[1]);

    expect(str_contains((string) file_get_contents($sourceRoot.'/bin/go-test'), 'golang:'.$image[1]))->toBeTrue();
});

/**
 * A Go-only change is an image change, because the binary is built into the
 * image. Without this the Docker workflow's path filter would let it merge
 * without the image ever being built or scanned.
 */
test('a change to the service rebuilds and rescans the image', function (): void {
    /** @var array{on: array{pull_request: array{paths: list<string>}}} $docker */
    $docker = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/docker.yml');

    expect($docker['on']['pull_request']['paths'])->toContain('services/**');
});
