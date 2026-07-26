<?php

declare(strict_types=1);

use Tests\TestCase;

/**
 * CI has run the Pest suite with `--parallel` since #582 while the local gate
 * stayed single-process, so every pre-push run paid ~3x the wall clock CI did.
 * These tests pin the local gate to the parallel run, keep the coverage floor
 * attached to it, and keep the browser suite — which binds Reverb and a live
 * server — out of the parallel path.
 */
function composerScript(string $name): string
{
    $scripts = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true)['scripts'];

    return implode(' ', (array) $scripts[$name]);
}

test('the local gate runs the suite in parallel', function (): void {
    expect(composerScript('test'))->toContain('artisan test --parallel');
});

test('the local gate still enforces the coverage floor', function (): void {
    expect(composerScript('test'))->toContain('--coverage')
        ->and(composerScript('test'))->toContain('--min=100');
});

test('the browser suite stays single-process', function (): void {
    expect(composerScript('test:browser'))->not->toContain('--parallel');
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
