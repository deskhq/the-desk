<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Build a throwaway repository root containing the given files, keyed by
 * repo-relative path. A `release-please-config.json` is written from the given
 * extra-files paths unless the caller supplies its own.
 *
 * @param  array<string, string>  $files
 * @param  array<int, string>  $extraFiles
 */
function fixtureRoot(array $files, array $extraFiles = []): string
{
    $root = sys_get_temp_dir().'/version-refs-'.uniqid();

    $files['release-please-config.json'] ??= json_encode([
        'packages' => [
            '.' => [
                'extra-files' => array_map(
                    static fn (string $path): array => ['type' => 'generic', 'path' => $path],
                    $extraFiles,
                ),
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        File::put($root.'/'.$path, $contents);
    }

    return $root;
}

/**
 * Run the guard against a fixture root and return its exit code and output.
 *
 * @return array{0: int, 1: string}
 */
function runGuard(string $root): array
{
    $exitCode = Artisan::call('release:check-version-refs', ['--root' => $root]);

    return [$exitCode, Artisan::output()];
}

afterEach(function (): void {
    foreach (File::directories(sys_get_temp_dir()) as $directory) {
        if (str_starts_with(basename((string) $directory), 'version-refs-')) {
            File::deleteDirectory($directory);
        }
    }
});

it('passes against the real repository', function (): void {
    $exitCode = Artisan::call('release:check-version-refs');

    expect($exitCode)->toBe(0);
});

it('fails when the release-please config is missing', function (): void {
    $root = sys_get_temp_dir().'/version-refs-'.uniqid();
    File::ensureDirectoryExists($root);

    [$exitCode, $output] = runGuard($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('release-please-config.json');
});

it('fails when a registered extra-file carries no annotation, naming the path', function (): void {
    $root = fixtureRoot(['docs/faq.md' => "No version here.\n"], ['docs/faq.md']);

    [$exitCode, $output] = runGuard($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('docs/faq.md')
        ->and($output)->toContain('no x-release-please');
});

it('fails when a registered extra-file does not exist', function (): void {
    $root = fixtureRoot([], ['docs/gone.md']);

    [$exitCode, $output] = runGuard($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('docs/gone.md');
});

it('accepts a registered extra-file listed as a bare string path', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'release-please-config.json' => json_encode([
            'packages' => ['.' => ['extra-files' => ['VERSION']]],
        ], JSON_THROW_ON_ERROR),
    ]);

    [$exitCode] = runGuard($root);

    expect($exitCode)->toBe(0);
});

it('fails when an operator-facing file names a version on an unstamped line', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'README.md' => "Intro.\n\nRun `git checkout v1.2.3` to install.\n",
    ], ['VERSION']);

    [$exitCode, $output] = runGuard($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('README.md:3');
});

it('accepts an annotated version line in a registered file', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'README.md' => "git checkout v1.2.3 <!-- x-release-please-version -->\n",
    ], ['VERSION', 'README.md']);

    [$exitCode] = runGuard($root);

    expect($exitCode)->toBe(0);
});

it('fails when a line is annotated but its file is not registered', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'README.md' => "git checkout v1.2.3 <!-- x-release-please-version -->\n",
    ], ['VERSION']);

    [$exitCode, $output] = runGuard($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('README.md:1')
        ->and($output)->toContain('not registered');
});

it('accepts versions inside an annotated block in a registered file', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'README.md' => "<!-- x-release-please-start-version -->\nv1.2.3\n1.2.3\n<!-- x-release-please-end -->\n",
    ], ['VERSION', 'README.md']);

    [$exitCode] = runGuard($root);

    expect($exitCode)->toBe(0);
});

it('fails for a version inside a block in a file that is not registered', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'README.md' => "<!-- x-release-please-start-version -->\nv1.2.3\n<!-- x-release-please-end -->\n",
    ], ['VERSION']);

    [$exitCode, $output] = runGuard($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('README.md:2');
});

it('accepts allowlisted lines that name a version deliberately', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'docker/install.sh' => "echo \"Error: --version must be a release like 1.6.1, got '\$VERSION'.\" >&2\n",
    ], ['VERSION']);

    [$exitCode] = runGuard($root);

    expect($exitCode)->toBe(0);
});

it('ignores dotted numbers that are not versions', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'README.md' => "Proxy to 127.0.0.1:8000 and block 169.254.169.254.\n",
    ], ['VERSION']);

    [$exitCode] = runGuard($root);

    expect($exitCode)->toBe(0);
});

it('scans nested docs pages', function (): void {
    $root = fixtureRoot([
        'VERSION' => "1.2.3 # x-release-please-version\n",
        'docs/src/content/docs/docs/self-hosting/upgrading.md' => "Move to v1.2.3.\n",
    ], ['VERSION']);

    [$exitCode, $output] = runGuard($root);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('self-hosting/upgrading.md:1');
});
