<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Every merge into `develop` used to burn a third full CI suite: release-please
 * reacts to the push by force-updating its `release-please--branches--develop`
 * branch, which fires a `synchronize` event on the open release PR and re-runs
 * the heavy workflows on a diff that touches only files release-please owns —
 * `VERSION`, the manifests, `CHANGELOG.md` — none of which can affect the app,
 * over code the feature PR's run and the push run had already tested. The same
 * applied to the stable release PR on `master`, and again to the push a merged
 * release PR produces. These tests pin the skip conditions so an edit to the
 * heavy workflows cannot silently reintroduce the triple run (#820).
 */
$workflow = fn (string $name): array => Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/'.$name);

/**
 * The condition that spares an automation-owned PR the heavy jobs: `head_ref`
 * is only set on pull request events, so every push, schedule, or dispatch run
 * passes the first disjunct untouched. Two branch prefixes qualify — the
 * release PRs release-please maintains (`release-please--`, #820) and the
 * candidate-baseline PR the release workflow opens against `develop` after a
 * stable release (`chore/candidate-baseline-`, #839); both carry diffs limited
 * to files release-please owns. The head-repo disjunct is what keeps the branch
 * name from becoming a CI bypass — `head_ref` is attacker-controlled, so a fork
 * branch named `release-please--anything` or `chore/candidate-baseline-anything`
 * would otherwise get every required check skipped (and skipped counts as
 * satisfied). A same-repo branch with either name needs write access, which is
 * already trusted.
 */
const AUTOMATION_PULL_REQUEST_SKIP = "github.event_name != 'pull_request' || !(startsWith(github.head_ref, 'release-please--') || startsWith(github.head_ref, 'chore/candidate-baseline-')) || github.event.pull_request.head.repo.full_name != github.repository";

/**
 * The multi-minute jobs and the workflows they live in. `commitlint` and
 * `dependency-review` are deliberately absent: they finish in seconds, so
 * skipping them on a release PR would buy nothing.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function heavyJobs(): array
{
    return [
        'the test suite' => ['tests.yml', 'ci'],
        'the browser suite' => ['tests.yml', 'browser'],
        'the linter' => ['lint.yml', 'quality'],
        'the CodeQL analysis' => ['codeql.yml', 'analyze'],
    ];
}

test('the heavy jobs skip automation-owned pull requests', function (string $file, string $job) use ($workflow): void {
    expect($workflow($file)['jobs'][$job]['if'] ?? null)->toBe(AUTOMATION_PULL_REQUEST_SKIP);
})->with(heavyJobs());

/*
 * The skip must stay a job-level `if`, never a `paths` filter on the
 * `pull_request` trigger: a skipped job still reports its check as *skipped*,
 * which GitHub treats as satisfied for required status checks — whereas a
 * workflow that never starts leaves them stuck on *Expected* and blocks the
 * release PR from merging.
 */
test('the heavy workflows still trigger on release pull requests so their checks report skipped', function (string $file) use ($workflow): void {
    $pullRequest = $workflow($file)['on']['pull_request'];

    expect($pullRequest['branches'])->toEqualCanonicalizing(['develop', 'master'])
        ->and($pullRequest)->not->toHaveKey('paths')
        ->and($pullRequest)->not->toHaveKey('paths-ignore');
})->with(collect(heavyJobs())->pluck(0)->unique()->values()->all());

/*
 * The skip only satisfies a required check whose ruleset context matches the
 * name the *skipped* job reports — and a matrix job skipped by a job-level
 * `if` never expands its matrix, so it reports under the raw, unexpanded name
 * (`ci`, `Analyze (${{ matrix.language }})`) instead of the expanded one the
 * ruleset requires (`ci (8.5)`, `Analyze (javascript-typescript)`). The
 * required context then sits on *Expected* forever and the release PR is
 * unmergeable (#825). Heavy jobs therefore must not use a matrix, and any
 * explicit display name must be a static string.
 */
test('the heavy jobs report static check names even when skipped', function (string $file, string $job) use ($workflow): void {
    $definition = $workflow($file)['jobs'][$job];

    expect($definition['strategy']['matrix'] ?? null)->toBeNull()
        ->and($definition['name'] ?? $job)->not->toContain('${{');
})->with(heavyJobs());

/*
 * Merging a release PR pushes a commit touching only the files release-please
 * owns, and that push used to run the full suite once more. Push runs carry no
 * required checks, so a plain `paths-ignore` is safe where a `paths` filter on
 * the pull request trigger would not be.
 */
test('a push touching only release-please-owned files does not run the heavy suites', function (string $file) use ($workflow): void {
    expect($workflow($file)['on']['push']['paths-ignore'] ?? null)
        ->toEqualCanonicalizing(['VERSION', 'CHANGELOG.md', '.release-please-manifest*.json']);
})->with(collect(heavyJobs())->pluck(0)->unique()->values()->all());

/*
 * The push a merged release PR produces is exactly what makes release-please
 * tag the version, so its own workflow must never be filtered by the paths the
 * heavy suites ignore.
 */
test('the release workflow still runs on every push, release-owned files included', function () use ($workflow): void {
    $push = $workflow('release-please.yml')['on']['push'];

    expect($push)->not->toHaveKey('paths')
        ->and($push)->not->toHaveKey('paths-ignore');
});
