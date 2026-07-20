<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

/**
 * The Vitest suite covers the layer the 100% PHP coverage gate cannot reach —
 * composables, lib helpers, the custom eslint rules. Until #644 it ran only when
 * someone remembered to type `npm run test:js`, so a PR could break every
 * frontend unit test and still go green. These tests pin it to CI and keep the
 * documented frontend gate from drifting away from what CI actually runs.
 */
function ciJobSteps(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/tests.yml')['jobs']['ci']['steps'];
}

test('the ci job runs the frontend test suite', function (): void {
    $step = collect(ciJobSteps())->first(
        static fn (array $step): bool => str_contains($step['run'] ?? '', 'npm run test:js'),
    );

    expect($step)->not->toBeNull('the Vitest suite must run on every push and PR');
});

test('the frontend suite runs before the slower php suite so a regression fails fast', function (): void {
    $steps = collect(ciJobSteps());

    $frontend = $steps->search(static fn (array $step): bool => str_contains($step['run'] ?? '', 'npm run test:js'));
    $install = $steps->search(static fn (array $step): bool => ($step['run'] ?? '') === 'npm ci');
    $php = $steps->search(static fn (array $step): bool => str_contains($step['run'] ?? '', 'artisan test'));

    expect($install)->not->toBeFalse()
        ->and($php)->not->toBeFalse()
        ->and($frontend)->toBeGreaterThan($install, 'Vitest needs node_modules')
        ->and($frontend)->toBeLessThan($php, 'the ~6s frontend suite must fail before the multi-minute PHP one');
});

test('the test:js script is the vitest entry point the gate assumes', function (): void {
    $scripts = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/package.json'), true)['scripts'];

    expect($scripts['test:js'] ?? '')->toContain('vitest');
});

test('the documented frontend gate names the frontend test suite', function (): void {
    foreach (['CONTRIBUTING.md', 'CLAUDE.md', 'README.md'] as $document) {
        $documented = str_contains((string) file_get_contents(dirname(__DIR__, 2).'/'.$document), 'test:js');

        expect($documented)->toBeTrue($document.' must document the frontend suite contributors are gated on');
    }
});
