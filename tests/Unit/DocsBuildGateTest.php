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

/**
 * Only the operating system matters here, not who hosts the runner: the guard
 * exists so a lockfile resolved on macOS is proven against the Linux Cloudflare
 * builder. The job takes its runner from `vars.CI_RUNNER` like every other one
 * (see RunnerLabelTest), and both sides of that switch are Ubuntu — so what this
 * asserts is that the docs job stays on the shared expression rather than being
 * pinned to a runner of its own, which is the only way it could drift off Linux.
 */
test('the docs job builds on linux so it reproduces the cloudflare builder', function () use ($docsJob): void {
    expect($docsJob()['runs-on'])->toMatch('/^\$\{\{\s*vars\.CI_RUNNER\s*\|\|\s*\'ubuntu-[^\']+\'\s*\}\}$/');
});

test('the docs guard runs on pull requests touching the docs project', function () use ($docsWorkflow): void {
    $triggers = $docsWorkflow()[true] ?? $docsWorkflow()['on'];

    expect($triggers['pull_request']['paths'])->toContain('docs/**')
        ->and($triggers['push']['paths'])->toContain('docs/**');
});

test('the docs guard re-runs when its own inputs change', function (string $event, string $path) use ($docsWorkflow): void {
    $triggers = $docsWorkflow()[true] ?? $docsWorkflow()['on'];

    expect($triggers[$event]['paths'])->toContain($path);
})->with(['push', 'pull_request'])->with([
    '.github/workflows/docs.yml',
    // The content config stamps the released version from this manifest, so it
    // can break the build without a single file under `docs/` changing.
    '.release-please-manifest.json',
]);

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

/**
 * The OpenAPI document is hand-authored, so nothing but a validator proves it
 * still parses as OpenAPI 3.1. `tests/Unit/OpenApiSpecTest.php` keeps it in
 * lockstep with the routes; this lint is what keeps it a *valid* spec (#531).
 */
test('the docs job lints the openapi spec against the 3.1 schema', function () use ($docsJob): void {
    $step = collect($docsJob()['steps'])->first(static fn (array $step): bool => runsInDocs($step, 'npm run openapi:lint'));

    expect($step)->not->toBeNull('the hand-authored spec must be schema-validated in CI');
});

test('the docs project provides the openapi lint script and its linter', function () use ($docsPath): void {
    $package = json_decode((string) file_get_contents($docsPath('package.json')), true);

    // The script must actually validate the spec: a placeholder that merely
    // exists under the right key would satisfy the workflow gate above while
    // checking nothing.
    expect($package['scripts']['openapi:lint'] ?? '')->toBe('redocly lint public/openapi.yaml')
        ->and($package['devDependencies'] ?? [])->toHaveKey('@redocly/cli');
});

test('the docs readme documents the pin a contributor must regenerate the lockfile with', function () use ($docsPath): void {
    $readme = (string) file_get_contents($docsPath('README.md'));

    expect($readme)->toContain('.nvmrc')
        ->and($readme)->toContain('22.16.0');
});
