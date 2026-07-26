<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The browser suite was left single-process on the premise that binding Reverb
 * and a live server made it unshardable (#647). It does not:
 * `pest-plugin-browser` reconnects every paratest worker to one shared
 * Playwright server and hands each worker its own HTTP server on its own free
 * port, and every Reverb channel is scoped by a UUID drawn from that worker's
 * own `testing_N` database. Measured at 4.4x on 12 cores (#948).
 *
 * The catch is `--processes`: left to its default (the core count) the suite
 * comes out both slower *and* flakier than at half that, because browsers, PHP
 * servers and Playwright then compete for the same cores and the geometry
 * assertions of #786 start reading layout before it settles. So the worker
 * count is capped rather than defaulted, and these tests pin both the cap and
 * the wiring that carries it into the local gate and CI.
 */
function runBrowserRunnerLib(string $snippet): Process
{
    $script = dirname(__DIR__, 2).'/bin/browser-tests';

    $process = new Process(['bash', '-c', 'BROWSER_TESTS_LIB=1 . '.escapeshellarg($script).'; '.$snippet]);
    $process->run();

    return $process;
}

/**
 * The pest invocation the runner would exec, one argument per line.
 *
 * @param  array<string, string>  $environment
 * @return list<string>
 */
function browserRunnerCommand(string $arguments = '', array $environment = []): array
{
    $exported = implode('', array_map(
        static fn (string $value, string $key): string => $key.'='.escapeshellarg($value).' ',
        $environment,
        array_keys($environment),
    ));

    $process = runBrowserRunnerLib($exported.'pest_command '.$arguments);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return array_values(array_filter(explode("\n", $process->getOutput()), strlen(...)));
}

function browserWorkerCount(int $cores): string
{
    $process = runBrowserRunnerLib('worker_count '.$cores);
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return trim($process->getOutput());
}

/**
 * @return array<string, mixed>
 */
function testsWorkflow(): array
{
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/tests.yml');

    return $workflow;
}

test('the browser suite runs in parallel', function (): void {
    expect(browserRunnerCommand())->toContain('--parallel');
});

test('the worker count is capped rather than left to pest to default', function (): void {
    expect(browserRunnerCommand())->toContain('--processes='.browserWorkerCount((int) trim(
        runBrowserRunnerLib('detect_cores')->getOutput()
    )));
});

test('the cap is half the available cores', function (): void {
    expect(browserWorkerCount(12))->toBe('6')
        ->and(browserWorkerCount(8))->toBe('4')
        ->and(browserWorkerCount(24))->toBe('12');
});

/*
 * GitHub's `ubuntu-latest` is a 4 vCPU runner, and a single-core machine must
 * still get a working command rather than `--processes=0`.
 */
test('the cap never falls below two workers', function (): void {
    expect(browserWorkerCount(4))->toBe('2')
        ->and(browserWorkerCount(2))->toBe('2')
        ->and(browserWorkerCount(1))->toBe('2')
        ->and(browserWorkerCount(0))->toBe('2');
});

test('an explicit worker count overrides the detected cores', function (): void {
    expect(browserRunnerCommand(environment: ['BROWSER_TEST_PROCESSES' => '3']))->toContain('--processes=3');
});

test('the runner forwards extra arguments to pest', function (): void {
    expect(browserRunnerCommand('--ci'))->toContain('--ci')
        ->and(browserRunnerCommand('--ci'))->toContain('tests/Browser');
});

test('the local gate runs the browser suite through the capped runner', function (): void {
    /** @var array<string, list<string>|string> $scripts */
    $scripts = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true)['scripts'];

    expect(implode(' ', (array) $scripts['test:browser']))->toContain('bin/browser-tests');
});

/*
 * The serial suite ran ~9 minutes against composer's 300s process timeout and
 * was killed mid-run (the parallel one has headroom, but not on a loaded
 * machine or a slower host).
 */
test('the local gate lets the browser suite outrun composer process timeout', function (): void {
    /** @var array<string, list<string>|string> $scripts */
    $scripts = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true)['scripts'];

    expect(implode(' ', (array) $scripts['test:browser']))->toContain('disableProcessTimeout');
});

test('CI runs the browser suite through the same capped runner', function (): void {
    /** @var array{jobs: array{browser: array{steps: list<array{run?: string}>}}} $workflow */
    $workflow = testsWorkflow();

    $commands = implode("\n", array_column($workflow['jobs']['browser']['steps'], 'run'));

    expect($commands)->toContain('bin/browser-tests')
        ->and($commands)->not->toContain('pest tests/Browser');
});

test('the documentation no longer claims the browser suite cannot be sharded', function (): void {
    $root = dirname(__DIR__, 2);

    expect((string) file_get_contents($root.'/CLAUDE.md'))->not->toContain('cannot be sharded')
        ->and((string) file_get_contents($root.'/README.md'))->not->toContain('cannot be sharded');
});

test('the documented browser gate spells out the capped parallel run', function (): void {
    $root = dirname(__DIR__, 2);

    expect((string) file_get_contents($root.'/CLAUDE.md'))->toContain('bin/browser-tests')
        ->and((string) file_get_contents($root.'/README.md'))->toContain('bin/browser-tests');
});
