<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

/**
 * `resources/js/actions` and `resources/js/routes` are git-ignored, Wayfinder-generated
 * files, so a fresh runner has neither — and with `@/actions/*` / `@/routes/*`
 * unresolvable, `import/order` mis-groups them against `@/types` and fails on sources
 * that are clean locally. The `quality` job papered over that by running the auto-fixing
 * `npm run lint` (always exit 0, rewrites discarded with the runner), so CI enforced
 * nothing. These tests pin the generation step and the check variant together: the check
 * only stays honest while the generation that makes it reproducible runs first (#414).
 */
function qualityJobSteps(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/lint.yml')['jobs']['quality']['steps'];
}

function qualityStepCommand(array $step): string
{
    return trim((string) ($step['run'] ?? ''));
}

/**
 * The step must *be* the command: an echoed or `|| true`-suffixed invocation would
 * satisfy a substring search while gating nothing.
 */
function runsLintCheck(array $step): bool
{
    return qualityStepCommand($step) === 'npm run lint:check';
}

/**
 * Matches the auto-fixing variant in any position — chained, wrapped or
 * argument-bearing — while the negative lookahead keeps `npm run lint:check` out.
 */
function runsAutoFixingLint(array $step): bool
{
    return preg_match('/(?:^|[\n;&|]\s*)npm run lint(?![:\w-])/', qualityStepCommand($step)) === 1;
}

/**
 * Anchored to a command position so a mention of the generator (in an `echo`, say)
 * cannot stand in for actually running it.
 */
function generatesWayfinderFiles(array $step): bool
{
    return preg_match('/(?:^|[\n;&|]\s*)php artisan wayfinder:generate\b/', qualityStepCommand($step)) === 1;
}

test('the quality job lints with the check variant, not the auto-fixing one', function (): void {
    $steps = collect(qualityJobSteps());

    expect($steps->first(runsLintCheck(...)))->not->toBeNull('CI must run `npm run lint:check`, which fails on violations')
        ->and($steps->contains(runsAutoFixingLint(...)))
        ->toBeFalse('`npm run lint` rewrites files and always exits 0, so it gates nothing on a runner');
});

test('the quality job generates the wayfinder files before linting', function (): void {
    $steps = collect(qualityJobSteps());

    $generate = $steps->search(generatesWayfinderFiles(...));
    $install = $steps->search(static fn (array $step): bool => qualityStepCommand($step) === 'npm ci');
    $lint = $steps->search(runsLintCheck(...));

    expect($generate)->not->toBeFalse('without `@/actions` and `@/routes`, `import/order` fails on sources that are clean locally')
        ->and($install)->not->toBeFalse('the job installs the node dependencies eslint runs from')
        ->and($lint)->not->toBeFalse('the lint step is what the generated files exist for')
        ->and($generate)->toBeGreaterThan($install, 'wayfinder generation belongs with the rest of the prepared environment')
        ->and($generate)->toBeLessThan($lint, 'the generated files must exist before eslint resolves imports against them');
});

test('the wayfinder generation matches the form variants vite builds', function (): void {
    $step = collect(qualityJobSteps())->first(generatesWayfinderFiles(...));

    expect($step)->not->toBeNull('there is no generation step to check the options of')
        ->and(str_contains(qualityStepCommand($step ?? []), '--with-form'))
        ->toBeTrue('vite.config.ts enables `formVariants`, so CI must generate the same surface');
});

test('the lint:check script is the non-fixing eslint entry point the gate assumes', function (): void {
    $scripts = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/package.json'), true)['scripts'];

    expect($scripts['lint:check'] ?? '')->toStartWith('eslint')
        ->and($scripts['lint:check'] ?? '')->not->toContain('--fix');
});
