<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * CI has run the Pest suite with `--parallel` since #582 while the local gate
 * stayed single-process, so every pre-push run paid ~3x the wall clock CI did.
 * These tests pin the local gate to the parallel run and keep the coverage floor
 * attached to it. The browser suite was excluded from the parallel path at the
 * time and has since joined it — tests/Unit/BrowserSuiteParallelTest.php owns
 * that half.
 */
function composerScript(string $name): string
{
    $scripts = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true)['scripts'];

    return implode(' ', (array) $scripts[$name]);
}

/**
 * The `ci` job's step that runs the PHP suite.
 */
function phpSuiteCiCommand(): string
{
    /** @var array{jobs: array{ci: array{steps: list<array{run?: string}>}}} $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/tests.yml');

    $steps = array_filter(
        array_column($workflow['jobs']['ci']['steps'], 'run'),
        static fn (string $run): bool => str_contains($run, 'artisan test'),
    );

    return implode("\n", $steps);
}

test('the local gate runs the suite in parallel', function (): void {
    expect(composerScript('test'))->toContain('artisan test --parallel');
});

/*
 * A run that has already gone red still cost the full wall clock, which hurts
 * most in the loop it happens most in: TDD writes the failing test first, then
 * waits out several hundred passing ones to be told the one it wanted to fail
 * did (#1075).
 *
 * `--stop-on-defect` rather than pest's own `--bail`, which is only sugar for it
 * (`Pest\Plugins\Bail` rewrites `--bail` into `--stop-on-failure
 * --stop-on-error`): Collision validates the options it forwards against
 * paratest's own input definition before it ever spawns pest, so `--bail` dies
 * here on `The "--bail" option does not exist` while `--stop-on-defect` — a real
 * paratest option — goes through. `bin/browser-tests` spells it the same way so
 * the two suites read alike.
 */
test('the local gate stops at the first defect', function (): void {
    expect(composerScript('test'))->toContain('--stop-on-defect');
});

test('CI stops the php suite at the first defect too', function (): void {
    expect(phpSuiteCiCommand())->toContain('--parallel')
        ->and(phpSuiteCiCommand())->toContain('--stop-on-defect');
});

test('the local gate still enforces the coverage floor', function (): void {
    expect(composerScript('test'))->toContain('--coverage')
        ->and(composerScript('test'))->toContain('--min=100');
});

test('the coverage gate never drags the browser suite in with it', function (): void {
    expect(composerScript('test'))->not->toContain('tests/Browser')
        ->and(composerScript('test'))->not->toContain('test:browser')
        ->and(composerScript('test'))->not->toContain('bin/browser-tests');
});

/*
 * The PHP gate has no env override to match the browser runner's
 * BROWSER_TEST_BAIL: `composer test` is a fixed string, and the full sweep is
 * simply the same command with the flag left off. That only helps someone who
 * knows it, so it is written down rather than implied.
 */
test('the escape hatch out of the php suite fail-fast is documented', function (): void {
    $documented = (string) file_get_contents(dirname(__DIR__, 2).'/.claude/rules/testing.md');

    expect($documented)->toContain('--stop-on-defect')
        ->and($documented)->toContain('artisan test --parallel --coverage --min=100');
});

test('the documented local gate spells out the parallel run', function (): void {
    $documented = (string) file_get_contents(dirname(__DIR__, 2).'/CLAUDE.md');

    expect($documented)->toContain('artisan test --parallel --coverage --min=100');
});

/*
 * The deadlocks of #812 were cured by guarding each worker's schema bootstrap,
 * deliberately rather than by throttling the run — capping `--processes` would
 * have bought the same green at the cost of the wall clock #647 won back.
 */
test('the local gate does not buy stability by throttling the workers', function (): void {
    expect(composerScript('test'))->not->toContain('--processes');
});

test('the base test case still routes setup through the deadlock guard', function (): void {
    $setUp = new ReflectionMethod(TestCase::class, 'setUp');

    $body = implode('', array_slice(
        (array) file((string) $setUp->getFileName()),
        $setUp->getStartLine() - 1,
        $setUp->getEndLine() - $setUp->getStartLine() + 1,
    ));

    expect($setUp->getDeclaringClass()->getName())->toBe(TestCase::class)
        ->and($body)->toContain('SchemaBootstrapGuard::run');
});
