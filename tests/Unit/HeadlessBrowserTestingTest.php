<?php

declare(strict_types=1);

/**
 * Browser tests drive real Playwright browsers, and `pest-plugin-browser` runs
 * them headless by default (`Playwright::$headless`), so this is a guard
 * against turning that off rather than a setting to hold in place. Every way of
 * flipping it costs more than the window it opens: `--headed` is rejected
 * outright under `--parallel`, which is how `bin/browser-tests` runs the suite,
 * and `->debug()` additionally calls `Only::enable()` — so a stray one pops a
 * window *and* silently shrinks the run to the single test carrying it, which
 * reports green having skipped everything else.
 *
 * The documentation half matters as much as the code half here: the rule exists
 * to keep an agent from opening a window on the user's desktop, and an agent
 * only follows what `CLAUDE.md` and the skills tell it.
 */
function repositoryRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Sources that would carry a headed browser into a run, keyed by their path.
 *
 * @return array<string, string>
 */
function headlessGuardedSources(): array
{
    $paths = [
        'tests/Pest.php',
        'bin/browser-tests',
        'composer.json',
        '.github/workflows/tests.yml',
        ...array_map(
            static fn (string $path): string => 'tests/Browser/'.basename($path),
            (array) glob(repositoryRoot().'/tests/Browser/*.php'),
        ),
    ];

    return array_combine($paths, array_map(
        static fn (string $path): string => (string) file_get_contents(repositoryRoot().'/'.$path),
        $paths,
    ));
}

/**
 * The guarded sources that contain any of the given fragments, so a failure
 * names the file rather than only the fragment.
 *
 * @param  list<string>  $fragments
 * @return list<string>
 */
function sourcesContaining(array $fragments): array
{
    $offenders = array_keys(array_filter(
        headlessGuardedSources(),
        static fn (string $contents): bool => array_any(
            $fragments,
            static fn (string $fragment): bool => str_contains($contents, $fragment),
        ),
    ));

    return array_values($offenders);
}

test('nothing puts playwright into headed mode', function (): void {
    expect(sourcesContaining(['--headed', 'headed()']))->toBe([]);
});

test('no browser test carries a debug call that would open a window and skip the rest', function (): void {
    expect(sourcesContaining(['->debug()']))->toBe([]);
});

test('the headless rule is documented where an agent will read it', function (): void {
    $conventions = (string) file_get_contents(repositoryRoot().'/CLAUDE.md');
    $skill = (string) file_get_contents(repositoryRoot().'/.agents/skills/implement-issue/SKILL.md');

    expect($conventions)->toContain('Browser Testing — always headless')
        ->and($conventions)->toContain('Never open a visible window')
        ->and($skill)->toContain('Browsers always run headless');
});
