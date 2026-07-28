<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * The landing page advertises the product with captures of the real shell, and
 * `.github/workflows/shell-capture.yml` is the only thing keeping them true: it
 * re-photographs the app on every change that could move it and fails when the
 * committed shots no longer match. That guard is easy to weaken by accident — a
 * dropped path, a `|| true`, an upload that no longer runs — and a weakened one
 * fails silently, which is precisely the failure mode this whole change exists
 * to remove. These tests pin its shape. See issue #1013.
 */
$workflowPath = dirname(__DIR__, 2).'/.github/workflows/shell-capture.yml';

$workflow = fn (): array => Yaml::parseFile($workflowPath);

$triggers = fn (): array => $workflow()[true] ?? $workflow()['on'];

$job = function () use ($workflow): array {
    $jobs = $workflow()['jobs'];

    return $jobs[array_key_first($jobs)];
};

$steps = fn (): array => $job()['steps'];

$repositoryPath = fn (string $path): string => dirname(__DIR__, 2).'/'.$path;

test('the gate runs the check itself rather than merely mentioning it', function () use ($steps): void {
    $step = collect($steps())->first(
        static fn (array $step): bool => trim((string) ($step['run'] ?? '')) === './bin/capture-shell --check'
    );

    // An echoed or `|| true`-suffixed invocation would satisfy a substring
    // search while gating nothing, so the step has to *be* the command.
    expect($step)->not->toBeNull('the workflow must run ./bin/capture-shell --check verbatim');
});

test('the gate builds the bundle before photographing it', function () use ($steps): void {
    $commands = collect($steps())->map(static fn (array $step): string => trim((string) ($step['run'] ?? '')));

    $build = $commands->search('npm run build');
    $check = $commands->search('./bin/capture-shell --check');

    expect($build)->not->toBeFalse('the capture serves public/build, so it must be built first')
        ->and($check)->toBeGreaterThan($build);
});

test('the gate watches every input that can move the shell', function (string $path) use ($triggers): void {
    expect($triggers()['pull_request']['paths'])->toContain($path);
})->with([
    'resources/js/**',
    'resources/css/**',
    // The fixture is half the picture: the workspace and its timestamps come
    // from the seeder, so a change there re-photographs just as surely as a
    // component does.
    'database/seeders/DemoSeeder.php',
    'app/Console/Commands/DemoSeedCommand.php',
    // The capture pipeline itself, so a change to how the shot is taken is
    // proven against the committed bytes rather than assumed.
    'bin/capture-shell',
    'bin/capture-shell.mjs',
    '.github/workflows/shell-capture.yml',
]);

/**
 * GitHub Actions does not resolve YAML anchors, so the push and pull_request
 * path lists are two hand-maintained copies. Letting them drift would leave one
 * event gating a narrower set than the other, which reads as the gate simply
 * not running.
 */
test('the push and pull_request triggers watch exactly the same inputs', function () use ($triggers): void {
    expect($triggers()['push']['paths'])->toBe($triggers()['pull_request']['paths']);
});

test('the gate uploads what it rendered so intended drift can be committed', function () use ($steps): void {
    $upload = collect($steps())->first(
        static fn (array $step): bool => str_contains((string) ($step['uses'] ?? ''), 'actions/upload-artifact')
    );

    expect($upload)->not->toBeNull()
        // Only useful on the failing run, and only if it runs *despite* the
        // failure — the default `success()` condition would skip it exactly when
        // it is needed.
        ->and($upload['if'] ?? null)->toBe('failure()')
        ->and($upload['with']['path'] ?? null)->toBe('storage/app/shell-capture');
});

test('the check writes its findings outside the committed captures', function () use ($repositoryPath): void {
    $script = (string) file_get_contents($repositoryPath('bin/capture-shell'));

    // A check that wrote over the committed captures would accept the drift it
    // is meant to report, and a clean `git status` would say so.
    expect($script)->toContain('ACTUAL_DIR="${CAPTURE_ACTUAL_DIR:-$REPO_ROOT/storage/app/shell-capture}"');
});

test('the gate runs on linux, matching the environment the captures are verified on', function () use ($job): void {
    expect($job()['runs-on'])->toMatch('/^\$\{\{\s*vars\.CI_RUNNER\s*\|\|\s*\'ubuntu-[^\']+\'\s*\}\}$/');
});

/**
 * The shell photographs its realtime state: with no broadcaster reachable the
 * masthead renders a "Reconnecting" pill and presence reads "0 of 7 active".
 * The bundle compiles the Reverb address in at build time, so these have to be
 * job-level env — set before the build — rather than step env.
 */
test('the gate pins the reverb address the bundle is compiled against', function (string $key, string $value) use ($job): void {
    expect((string) ($job()['env'][$key] ?? null))->toBe($value);
})->with([
    ['REVERB_HOST', '127.0.0.1'],
    ['REVERB_PORT', '8080'],
    ['REVERB_SERVER_HOST', '127.0.0.1'],
    ['REVERB_SERVER_PORT', '8080'],
]);

test('every capture the landing page imports is committed', function (string $variant) use ($repositoryPath): void {
    expect($repositoryPath("resources/js/images/shell/{$variant}.png"))->toBeFile();
})->with(['desktop-light', 'desktop-dark', 'mobile-light', 'mobile-dark']);

/**
 * The README's screenshot is produced by the same run rather than captured by
 * hand — that was the second half of the drift this change removes.
 */
test('the readme screenshot is a copy of the desktop capture, not a separate shot', function () use ($repositoryPath): void {
    expect(file_get_contents($repositoryPath('.github/screenshot-app.png')))
        ->toBe(file_get_contents($repositoryPath('resources/js/images/shell/desktop-light.png')));
});
