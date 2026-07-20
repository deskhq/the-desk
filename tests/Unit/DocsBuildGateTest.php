<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * `docs/` is a self-contained host-side npm project excluded from every app
 * quality gate, and its lockfile is generated on macOS while Cloudflare Pages
 * builds on Linux. A macOS-resolved lock can omit the top-level `@emnapi/*`
 * entries Linux needs, so `npm ci` aborts with EUSAGE — which is how #614 broke
 * the deploy, with the failure surfacing only in the Cloudflare build log after
 * merge. These tests pin the Linux `npm ci` + build guard and its Node pin so
 * the same desync cannot land a second time. See issue #622.
 */
$docsWorkflow = fn (): array => Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/docs.yml');

$docsPath = fn (string $file): string => dirname(__DIR__, 2).'/docs/'.$file;

$docsJob = function () use ($docsWorkflow): array {
    $jobs = $docsWorkflow()['jobs'];

    return $jobs[array_key_first($jobs)];
};

/**
 * The step must *be* the command, not merely mention it: an echoed or
 * `|| true`-suffixed invocation would satisfy a substring search while gating
 * nothing. It must also run inside `docs/`, not the repo root.
 */
function runsInDocs(array $step, string $command): bool
{
    return trim((string) ($step['run'] ?? '')) === $command
        && ($step['working-directory'] ?? null) === 'docs';
}

test('the docs job installs from the lockfile and builds the site', function (string $command) use ($docsJob): void {
    $step = collect($docsJob()['steps'])->first(static fn (array $step): bool => runsInDocs($step, $command));

    expect($step)->not->toBeNull($command.' must run inside docs/ on every docs change');
})->with(['npm ci', 'npm run build']);

test('the docs job builds on linux so it reproduces the cloudflare builder', function () use ($docsJob): void {
    expect($docsJob()['runs-on'])->toBe('ubuntu-latest');
});

test('the docs guard runs on pull requests touching the docs project', function () use ($docsWorkflow): void {
    $triggers = $docsWorkflow()[true] ?? $docsWorkflow()['on'];

    expect($triggers['pull_request']['paths'])->toContain('docs/**')
        ->and($triggers['push']['paths'])->toContain('docs/**');
});

test('the docs guard re-runs when the guard itself changes', function (string $event) use ($docsWorkflow): void {
    $triggers = $docsWorkflow()[true] ?? $docsWorkflow()['on'];

    expect($triggers[$event]['paths'])->toContain('.github/workflows/docs.yml');
})->with(['push', 'pull_request']);

test('the docs job checks out the whole repo, not a sparse docs directory', function () use ($docsJob): void {
    $checkout = collect($docsJob()['steps'])
        ->first(static fn (array $step): bool => str_contains((string) ($step['uses'] ?? ''), 'actions/checkout'));

    expect($checkout)->not->toBeNull()
        ->and($checkout['with'] ?? [])->not->toHaveKey('sparse-checkout', 'the build reads the repo-root release-please manifest');
});

test('the docs job uses the pinned node version rather than a floating major', function () use ($docsJob): void {
    $setupNode = collect($docsJob()['steps'])
        ->first(static fn (array $step): bool => str_contains((string) ($step['uses'] ?? ''), 'actions/setup-node'));

    expect($setupNode)->not->toBeNull()
        ->and($setupNode['with']['node-version-file'] ?? null)->toBe('docs/.nvmrc')
        ->and($setupNode['with'])->not->toHaveKey('node-version', 'an inline version would drift from the .nvmrc pin');
});

test('the docs node pin matches the cloudflare builder', function () use ($docsPath): void {
    expect(trim((string) file_get_contents($docsPath('.nvmrc'))))->toBe('22.16.0');
});

test('the declared engines agree with the node pin', function () use ($docsPath): void {
    $engines = json_decode((string) file_get_contents($docsPath('package.json')), true)['engines'];

    expect($engines['node'])->toBe(trim((string) file_get_contents($docsPath('.nvmrc'))))
        ->and($engines['npm'])->toBe('10.9.2');
});

test('the docs readme documents the pin a contributor must regenerate the lockfile with', function () use ($docsPath): void {
    $readme = (string) file_get_contents($docsPath('README.md'));

    expect($readme)->toContain('.nvmrc')
        ->and($readme)->toContain('22.16.0');
});
