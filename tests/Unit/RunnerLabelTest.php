<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Where CI runs is a repository setting, not a code change. Every job resolves
 * its runner from the `CI_RUNNER` repository variable, so setting it to a
 * Blacksmith label moves the whole fleet there and clearing it drops everything
 * back onto GitHub-hosted runners — with no workflow edit and no PR either way
 * (issue #782).
 *
 * `uses:` cannot be templated with an expression, so the two Blacksmith-only
 * Docker actions cannot follow the same variable directly. They live behind
 * `.github/actions/docker-build` instead, which branches on the label it is
 * handed. These tests keep both halves honest: a job that re-pins a literal
 * label, or a Blacksmith action that leaks back into a workflow, strands the
 * switch without failing anything else.
 */
function workflowFiles(): array
{
    return glob(dirname(__DIR__, 2).'/.github/workflows/*.yml') ?: [];
}

function workflowJobs(string $file): array
{
    return Yaml::parseFile($file)['jobs'] ?? [];
}

function dockerBuildActionPath(): string
{
    return dirname(__DIR__, 2).'/.github/actions/docker-build/action.yml';
}

function dockerBuildAction(): array
{
    return Yaml::parseFile(dockerBuildActionPath());
}

/**
 * The one shape a job's `runs-on` may take. The fallback is captured rather
 * than matched literally so the assertion can say *why* it has to be a
 * GitHub-hosted label: an unset variable must degrade to a runner that exists,
 * not to an empty `runs-on` that fails the job.
 */
function runnerExpressionFallback(string $runsOn): ?string
{
    $matched = preg_match(
        '/^\$\{\{\s*vars\.CI_RUNNER\s*\|\|\s*\'(?<fallback>[^\']+)\'\s*\}\}$/',
        $runsOn,
        $matches,
    );

    return $matched === 1 ? $matches['fallback'] : null;
}

test('every job resolves its runner from the CI_RUNNER variable', function (string $file): void {
    $jobs = workflowJobs($file);

    expect($jobs)->not->toBeEmpty(basename($file).' parsed to no jobs, so this test would scan nothing');

    foreach ($jobs as $id => $job) {
        $where = basename($file).' → '.$id;

        expect($job['runs-on'] ?? null)->not->toBeNull($where.' must declare where it runs');
        expect(runnerExpressionFallback((string) $job['runs-on']))
            ->not->toBeNull($where.' pins a literal runner label; it must read `vars.CI_RUNNER` so the fleet can be moved without a code change');
    }
})->with(fn (): array => workflowFiles());

test('an unset CI_RUNNER falls back to a github-hosted linux runner', function (string $file): void {
    foreach (workflowJobs($file) as $id => $job) {
        expect(runnerExpressionFallback((string) $job['runs-on']))
            ->toBe('ubuntu-latest', basename($file).' → '.$id.' must fall back to a GitHub-hosted label, so clearing the variable is always safe');
    }
})->with(fn (): array => workflowFiles());

test('no workflow pins a provider-specific runner label', function (string $file): void {
    $lines = collect(explode("\n", (string) file_get_contents($file)))
        ->filter(static fn (string $line): bool => (bool) preg_match('/^\s*(-\s*)?runs-on:/', $line))
        ->filter(static fn (string $line): bool => ! str_contains($line, 'vars.CI_RUNNER'));

    expect($lines->all())->toBeEmpty(basename($file).' declares a runner outside the variable');
})->with(fn (): array => workflowFiles());

test('blacksmith actions live only behind the docker-build composite action', function (string $file): void {
    expect((string) file_get_contents($file))
        ->not->toContain('useblacksmith/', basename($file).' calls a Blacksmith-only action directly; `uses:` cannot be templated, so it belongs in .github/actions/docker-build');
})->with(fn (): array => workflowFiles());

test('the composite action is what actually holds the two provider paths', function (): void {
    expect(dockerBuildActionPath())->toBeFile();

    $action = dockerBuildAction();

    expect($action['runs']['using'] ?? null)->toBe('composite')
        ->and(array_keys($action['inputs'] ?? []))
        ->toEqualCanonicalizing(['runner', 'context', 'file', 'platforms', 'push', 'load', 'tags', 'labels']);
});

/**
 * An action manifest is templated in full — input descriptions included — and
 * `vars` is not a context it can resolve. So a `${{ vars.CI_RUNNER }}` written
 * as *prose* in a description is still evaluated, and the whole action fails to
 * load with "Unrecognized named-value: 'vars'". That is why the action is handed
 * the label as an input; naming the variable in prose is fine, writing the
 * expression is not.
 */
test('the composite action manifest resolves no context an action cannot see', function (): void {
    $manifest = implode("\n", array_filter(
        explode("\n", (string) file_get_contents(dockerBuildActionPath())),
        static fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));

    preg_match_all('/\$\{\{(?<expression>[^}]*)\}\}/', $manifest, $matches);

    expect($matches['expression'])->not->toBeEmpty('the manifest passes its inputs through expressions, so this must find some');

    foreach ($matches['expression'] as $expression) {
        expect($expression)
            ->not->toContain('vars.')
            ->not->toContain('secrets.');
    }
});

test('the composite action selects its path from the runner label it is handed', function (): void {
    $steps = collect(dockerBuildAction()['runs']['steps']);

    $blacksmith = $steps->filter(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'useblacksmith/'));
    $github = $steps->filter(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'docker/'));

    expect($blacksmith)->toHaveCount(2, 'the Blacksmith path is a builder plus a build')
        ->and($github)->toHaveCount(2, 'the GitHub-hosted path is a buildx builder plus a build');

    foreach ($blacksmith as $step) {
        expect($step['if'] ?? '')->toContain("startsWith(inputs.runner, 'blacksmith')");
    }

    // The complement, not a second positive condition: two overlapping
    // conditions would run both builders on the same runner.
    foreach ($github as $step) {
        expect($step['if'] ?? '')->toContain("! startsWith(inputs.runner, 'blacksmith')");
    }
});

/**
 * The layer cache is not a nicety on this path. The extension layer fetches
 * `redis` from pecl.php.net, so an uncached rebuild on every run turned each
 * PECL outage into a red build on a commit that changed nothing (#626). The
 * Blacksmith builder mounts a persistent cache of its own; the GitHub-hosted
 * builder only reuses layers if the step asks for the Actions cache.
 */
test('the github-hosted build path caches its layers through the actions cache', function (): void {
    $build = collect(dockerBuildAction()['runs']['steps'])
        ->first(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'docker/build-push-action@'));

    expect($build)->not->toBeNull()
        ->and($build['with']['cache-from'] ?? null)->toBe('type=gha')
        ->and($build['with']['cache-to'] ?? '')->toStartWith('type=gha,mode=max');
});

test('both provider paths build the same image from the same inputs', function (string $input): void {
    $builds = collect(dockerBuildAction()['runs']['steps'])
        ->filter(static fn (array $step): bool => str_contains((string) ($step['uses'] ?? ''), '/build-push-action@'));

    expect($builds)->toHaveCount(2);

    foreach ($builds as $build) {
        expect($build['with'][$input] ?? null)
            ->toBe('${{ inputs.'.$input.' }}', $build['uses'].' drops the '.$input.' input, so the two paths would build different images');
    }
})->with(['context', 'file', 'platforms', 'push', 'load', 'tags', 'labels']);

test('every docker build in the workflow goes through the composite action', function (): void {
    $steps = collect(workflowJobs(dirname(__DIR__, 2).'/.github/workflows/docker.yml'))
        ->flatMap(static fn (array $job): array => $job['steps'] ?? []);

    $builds = $steps->filter(static fn (array $step): bool => ($step['uses'] ?? null) === './.github/actions/docker-build');

    expect($builds)->not->toBeEmpty('the production image must still be built somewhere');

    foreach ($builds as $build) {
        expect($build['with']['runner'] ?? null)
            ->toBe('${{ vars.CI_RUNNER }}', 'the composite action cannot read the variable itself; the call site has to hand it the label the job resolved');
    }

    $direct = $steps->filter(static fn (array $step): bool => (bool) preg_match('#/(build-push|setup-docker-builder|setup-buildx)-?action@#', (string) ($step['uses'] ?? '')));

    expect($direct->all())->toBeEmpty('a build outside the composite action only works on one provider');
});

test('dependabot keeps the composite action pins up to date', function (): void {
    $updates = Yaml::parseFile(dirname(__DIR__, 2).'/.github/dependabot.yml')['updates'];

    $directories = collect($updates)
        ->filter(static fn (array $update): bool => $update['package-ecosystem'] === 'github-actions')
        ->flatMap(static fn (array $update): array => $update['directories'] ?? [$update['directory']]);

    // Actions pinned outside `.github/workflows` are invisible to dependabot
    // unless their directory is listed, so moving them into a composite action
    // would silently drop them out of the weekly bump.
    expect($directories->all())->toContain('/.github/actions/docker-build');
});

test('contributing documents how to move the fleet between providers', function (): void {
    $contributing = (string) file_get_contents(dirname(__DIR__, 2).'/CONTRIBUTING.md');

    expect($contributing)
        ->toContain('CI_RUNNER')
        ->toContain('blacksmith-4vcpu-ubuntu-2404')
        ->toContain('ubuntu-latest');
});
