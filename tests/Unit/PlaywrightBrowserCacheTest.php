<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Two jobs drive a real Chromium — `tests.yml`'s `browser` and
 * `shell-capture.yml`'s `capture` — and both used to pay for it with a single
 * `playwright install --with-deps chromium` on every run. Measured over eight
 * runs that step costs ~24s, and it splits in two: ~14s of `apt-get` (nine font
 * packages; `ubuntu-latest` already carries Chromium's shared libraries) and
 * ~10s of downloading the ~300 MB browser bundle (#1078).
 *
 * Only the second half is files, so only the second half is cacheable. These
 * tests pin the shape that follows: the two halves are separate steps so the log
 * keeps timing them apart, the bundle is keyed on the Playwright version npm
 * actually resolved, and the install still runs unconditionally so a cold or
 * stale entry is slower rather than a job with no browser. They also keep the
 * two workflows from drifting apart, since a browser installed one way here and
 * another way there is how one of them quietly stops being cached.
 */

/**
 * @return list<array<string, mixed>>
 */
function browserInstallSteps(string $workflow, string $job): array
{
    /** @var array{jobs: array<string, array{steps: list<array<string, mixed>>}>} $parsed */
    $parsed = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/'.$workflow);

    return array_values(array_filter(
        $parsed['jobs'][$job]['steps'],
        static fn (array $step): bool => str_contains((string) ($step['name'] ?? ''), 'Playwright'),
    ));
}

/**
 * The two jobs that install a browser, as `[workflow, job]` pairs.
 *
 * @return array<string, array{string, string}>
 */
function browserInstallJobs(): array
{
    return [
        'tests.yml → browser' => ['tests.yml', 'browser'],
        'shell-capture.yml → capture' => ['shell-capture.yml', 'capture'],
    ];
}

test('both browser jobs install Playwright the same way', function (): void {
    [$tests, $capture] = array_map(
        static fn (array $job): array => browserInstallSteps(...$job),
        array_values(browserInstallJobs()),
    );

    expect($tests)->not->toBeEmpty('the browser job has to install a browser somewhere')
        ->and($capture)->toBe($tests, 'the two workflows install Playwright differently; whatever is true of one has to be true of the other');
});

test('the apt half and the download half are separate steps', function (string $workflow, string $job): void {
    $commands = array_map(
        static fn (array $step): string => (string) ($step['run'] ?? ''),
        browserInstallSteps($workflow, $job),
    );

    expect($commands)->toContain('npx playwright install-deps chromium')
        ->and($commands)->toContain('npx playwright install chromium')
        ->and(implode("\n", $commands))->not->toContain('--with-deps', 'the combined command times the fonts and the browser download as one number, and only one of the two is cacheable');
})->with(browserInstallJobs());

test('the browser bundle is restored from the actions cache', function (string $workflow, string $job): void {
    $cache = collect(browserInstallSteps($workflow, $job))
        ->first(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/cache@'));

    expect($cache)->not->toBeNull('nothing caches the browser bundle, so every run re-downloads it')
        ->and($cache['with']['path'] ?? null)->toBe('~/.cache/ms-playwright');
})->with(browserInstallJobs());

/*
 * `package.json` declares a caret range, so keying on it would hold a patch bump
 * on the entry cut for the previous version — and a Playwright patch ships a new
 * Chromium revision. Reading the version out of the installed package is reading
 * what the lockfile resolved to.
 */
test('the cache key follows the Playwright version the lockfile resolved', function (string $workflow, string $job): void {
    $steps = collect(browserInstallSteps($workflow, $job));

    $resolve = $steps->first(static fn (array $step): bool => isset($step['id']) && str_contains((string) ($step['run'] ?? ''), 'GITHUB_OUTPUT'));

    expect($resolve)->not->toBeNull('no step resolves the Playwright version, so the key cannot carry it');
    expect($resolve['run'])->toContain("require('playwright/package.json')")
        ->and($resolve['run'])->not->toContain("require('./package.json')");

    $cache = $steps->first(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/cache@'));

    expect((string) ($cache['with']['key'] ?? ''))
        ->toContain('steps.'.$resolve['id'].'.outputs.version')
        ->toContain('runner.os');
})->with(browserInstallJobs());

test('the version is resolved after the dependencies it reads are installed', function (string $workflow, string $job): void {
    /** @var array{jobs: array<string, array{steps: list<array<string, mixed>>}>} $parsed */
    $parsed = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/'.$workflow);

    $names = array_map(
        static fn (array $step): string => (string) ($step['name'] ?? ($step['uses'] ?? '')),
        $parsed['jobs'][$job]['steps'],
    );

    $install = array_search('Install Node Dependencies', $names, true);
    $resolve = array_search('Resolve Playwright Version', $names, true);

    expect($install)->not->toBeFalse()
        ->and($resolve)->not->toBeFalse()
        ->and($resolve)->toBeGreaterThan($install, 'the version is read out of node_modules, which npm ci has to have written first');
})->with(browserInstallJobs());

/*
 * The cache is an optimisation, and an optimisation that can leave a job without
 * a browser is a liability. `install` re-verifies whatever the cache restored and
 * fetches what is missing, so gating it on a cache hit is the one way to turn a
 * cold or half-written entry into a red run.
 */
test('a cache miss still installs a working browser', function (string $workflow, string $job): void {
    foreach (browserInstallSteps($workflow, $job) as $step) {
        expect($step)->not->toHaveKey('if', ($step['name'] ?? '').' is conditional; the install has to run whether or not the cache hit');
    }
})->with(browserInstallJobs());
