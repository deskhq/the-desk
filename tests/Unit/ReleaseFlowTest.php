<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The release flow spans two branches: `master` cuts stable releases, `develop`
 * cuts `-rc` candidates. release-please reads its config *and* its manifest from
 * whichever branch it targets, so the two lines are driven by two separate pairs
 * of files that live side by side on both branches — nothing diverges per branch,
 * which is what makes a `develop` -> `master` merge unable to turn a stable
 * release into a candidate.
 *
 * These tests pin that arrangement to the checked-in files, because every failure
 * mode here is silent: a missing `prerelease` flag publishes a candidate as the
 * latest stable release, and an `extra-files` entry on the candidate config
 * stamps `-rc` strings into the install instructions we ship to self-hosters.
 */
function repositoryPath(string $relative): string
{
    return dirname(__DIR__, 2).'/'.$relative;
}

/**
 * @return array<string, mixed>
 */
function readJsonFile(string $relative): array
{
    $decoded = json_decode((string) file_get_contents(repositoryPath($relative)), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * @return array<string, mixed>
 */
function releaseConfigPackage(string $relative): array
{
    $config = readJsonFile($relative);

    expect($config)->toHaveKey('packages');
    expect($config['packages'])->toBeArray()->toHaveKey('.');

    /** @var array<string, mixed> $package */
    $package = $config['packages']['.'];

    return $package;
}

test('the candidate config cuts numbered release candidates', function (): void {
    $package = releaseConfigPackage('release-please-config.develop.json');

    expect($package['prerelease'] ?? null)->toBeTrue()
        ->and($package['prerelease-type'] ?? null)->toBe('rc.0')
        ->and($package['versioning'] ?? null)->toBe('prerelease');
});

/*
 * CHANGELOG.md is master's, and it stays master's. If the candidate line wrote
 * to it too, every promotion would carry a `1.12.0-rc.N` section into master and
 * land it directly above the `1.12.0` section describing the same changes — a
 * merge conflict at the top of the file on every release, and duplicated history
 * whichever way it was resolved. `skip-changelog` suppresses only the file: the
 * release notes are built before it is consulted, so an rc release still gets
 * full notes on GitHub.
 */
test('the candidate line writes no changelog', function (): void {
    expect(releaseConfigPackage('release-please-config.develop.json')['skip-changelog'] ?? null)
        ->toBeTrue();
});

test('the stable line keeps ownership of the changelog', function (): void {
    expect(releaseConfigPackage('release-please-config.json'))
        ->not->toHaveKey('skip-changelog')
        ->and(releaseConfigPackage('release-please-config.develop.json'))
        ->not->toHaveKey('changelog-path');
});

test('the candidate config stamps no version references', function (): void {
    $package = releaseConfigPackage('release-please-config.develop.json');

    expect($package)->not->toHaveKey('extra-files');
});

/*
 * The acceptance criterion this file exists for: merging `develop` into `master`
 * must not be able to make `master` cut a candidate. It cannot, because the two
 * lines are configured by two separate files rather than by two versions of one
 * file — so a merge brings the candidate config across unchanged instead of
 * overwriting the stable one, and `master`'s workflow never reads it.
 */
test('the stable config carries no candidate settings', function (string $option): void {
    expect(releaseConfigPackage('release-please-config.json'))->not->toHaveKey($option);
})->with(['prerelease', 'prerelease-type', 'versioning']);

test('the two release lines are configured by separate files', function (): void {
    expect(repositoryPath('release-please-config.json'))->toBeReadableFile()
        ->and(repositoryPath('release-please-config.develop.json'))->toBeReadableFile()
        ->and(repositoryPath('.release-please-manifest.json'))->toBeReadableFile()
        ->and(repositoryPath('.release-please-manifest.develop.json'))->toBeReadableFile();
});

test('each release line tracks its own version independently', function (): void {
    expect(readJsonFile('.release-please-manifest.json'))->toHaveKey('.')
        ->and(readJsonFile('.release-please-manifest.develop.json'))->toHaveKey('.');
});

/**
 * @return array<string, mixed>
 */
function readWorkflow(string $name): array
{
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(repositoryPath('.github/workflows/'.$name));

    return $workflow;
}

/**
 * The tag list docker/metadata-action derives, as its raw multi-line input.
 */
function imageTagRules(): string
{
    $workflow = readWorkflow('docker.yml');

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $workflow['jobs']['build']['steps'];

    foreach ($steps as $step) {
        if (($step['id'] ?? null) === 'meta') {
            return (string) $step['with']['tags'];
        }
    }

    throw new RuntimeException('docker.yml has no `meta` step deriving image tags.');
}

/*
 * metadata-action suppresses `latest` and the `{{major}}.{{minor}}` alias for a
 * SemVer prerelease, but only for `type=semver` rules — `type=ref,event=tag`
 * applies `latest` unconditionally, and the flag is taken from the first rule
 * that sets it. So a candidate can only steal `latest` from a stable release if
 * a non-semver tag rule is added here.
 */
test('candidate images cannot claim the stable tags', function (): void {
    expect(imageTagRules())->not->toContain('type=ref')
        ->and(imageTagRules())->not->toContain('type=match');
});

test('candidate tags publish a moving rc alias', function (): void {
    expect(imageTagRules())->toContain('type=raw,value=rc,enable=');
});

test('the moving rc alias is gated on a candidate tag', function (): void {
    $rule = collect(explode("\n", imageTagRules()))
        ->first(fn (string $line): bool => str_contains($line, 'value=rc,'));

    expect($rule)->toContain("contains(github.ref_name, '-rc.')");
});

test('the moving edge tag stays on the default branch', function (): void {
    expect(imageTagRules())->toContain('type=raw,value=edge,enable={{is_default_branch}}');
});

/**
 * The `with:` inputs of the release-please action step inside a named job.
 *
 * @return array<string, mixed>
 */
function releaseActionInputs(string $job): array
{
    $workflow = readWorkflow('release-please.yml');

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $workflow['jobs'][$job]['steps'];

    foreach ($steps as $step) {
        if (str_contains((string) ($step['uses'] ?? ''), 'release-please-action')) {
            /** @var array<string, mixed> $inputs */
            $inputs = $step['with'];

            return $inputs;
        }
    }

    throw new RuntimeException("Job `{$job}` does not run the release-please action.");
}

test('both release lines run off a push to their own branch', function (): void {
    expect(readWorkflow('release-please.yml')['on']['push']['branches'])
        ->toEqualCanonicalizing(['master', 'develop']);
});

test('the stable line releases from the stable config pair', function (): void {
    expect(releaseActionInputs('release-please'))
        ->toMatchArray([
            'config-file' => 'release-please-config.json',
            'manifest-file' => '.release-please-manifest.json',
        ]);
});

/*
 * The stable job must never carry `target-branch`: it releases whatever branch
 * the push came from, and it is gated to master. Pinning it would be harmless
 * today but would silently survive a default-branch rename.
 */
test('the stable line is gated to master', function (): void {
    $job = readWorkflow('release-please.yml')['jobs']['release-please'];

    expect($job['if'])->toContain("refs/heads/master")
        ->and(releaseActionInputs('release-please'))->not->toHaveKey('target-branch');
});

test('the candidate line releases from the candidate config pair', function (): void {
    expect(releaseActionInputs('prerelease'))
        ->toMatchArray([
            'target-branch' => 'develop',
            'config-file' => 'release-please-config.develop.json',
            'manifest-file' => '.release-please-manifest.develop.json',
        ]);
});

test('the candidate line is gated to develop', function (): void {
    expect(readWorkflow('release-please.yml')['jobs']['prerelease']['if'])
        ->toContain('refs/heads/develop');
});

/**
 * The shell body of the step that appends the GHCR pull reference to a release.
 */
function releaseNoteScript(): string
{
    $workflow = readWorkflow('docker.yml');

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $workflow['jobs']['link-release-image']['steps'];

    return (string) $steps[0]['run'];
}

/*
 * `link-release-image` fires for every `v*` tag, candidates included — which is
 * what we want, since a candidate-tester needs the pull reference more than
 * anyone. But the note it appends reads as an invitation to deploy, so a
 * candidate has to say plainly that it is not supported in production.
 */
test('candidate releases warn against running them in production', function (): void {
    expect(releaseNoteScript())
        ->toContain('-rc.')
        ->toContain('not supported for production');
});

/**
 * Run the real `link-release-image` script against a stubbed `gh`, and return
 * the release body it would have written.
 *
 * Asserting on the script's source only proves the warning text is present
 * somewhere; it cannot catch the note being assembled wrongly — command
 * substitution strips trailing newlines, so a heading built with its own
 * trailing blank line renders glued to the section that follows it. Executing
 * the script is the only way to see what a reader actually gets.
 */
function renderReleaseNote(string $tag): string
{
    $sandbox = sys_get_temp_dir().'/release-note-'.bin2hex(random_bytes(6));
    mkdir($sandbox.'/bin', 0o777, true);

    file_put_contents($sandbox.'/bin/gh', <<<'SHELL'
        #!/usr/bin/env bash
        # Stand in for the two `gh` calls the step makes: read the release body
        # release-please wrote, then write the body back with the note appended.
        if [ "$2" = "view" ]; then
            printf '### Features\n\n* something shipped\n'
            exit 0
        fi
        while [ "$#" -gt 0 ]; do
            if [ "$1" = "--notes" ]; then
                printf '%s' "$2" > "$SANDBOX/body.md"
                exit 0
            fi
            shift
        done
        SHELL);
    chmod($sandbox.'/bin/gh', 0o755);

    $process = new Process(
        ['bash', '-c', releaseNoteScript()],
        env: [
            'PATH' => $sandbox.'/bin:'.getenv('PATH'),
            'SANDBOX' => $sandbox,
            'TAG' => $tag,
            'REPO' => 'emmpaul/the-desk',
            'GH_TOKEN' => 'stub',
        ],
    );
    $process->mustRun();

    return (string) file_get_contents($sandbox.'/body.md');
}

test('a candidate release note warns before it invites a pull', function (): void {
    $body = renderReleaseNote('v1.12.0-rc.3');

    expect($body)
        ->toContain('🚧 Release candidate')
        ->toContain('published for testing ahead of 1.12.0')
        ->toContain('not supported for production')
        ->toContain('ghcr.io/emmpaul/the-desk:1.12.0-rc.3');
});

test('the candidate warning is a section of its own, not glued to the next one', function (): void {
    expect(renderReleaseNote('v1.12.0-rc.3'))
        ->toContain("releases/latest).\n\n### 📦 Docker image");
});

test('a stable release note carries no candidate warning', function (): void {
    $body = renderReleaseNote('v1.12.0');

    expect($body)->not->toContain('Release candidate')
        ->and($body)->toContain('ghcr.io/emmpaul/the-desk:1.12.0')
        ->and($body)->toContain('### 📦 Docker image');
});

test('the note is appended to the notes release-please wrote, not replacing them', function (): void {
    expect(renderReleaseNote('v1.12.0'))->toStartWith("### Features\n\n* something shipped");
});

test('the release note still links the image for a candidate', function (): void {
    expect(readWorkflow('docker.yml')['jobs']['link-release-image']['if'])
        ->toContain("startsWith(github.ref, 'refs/tags/v')")
        ->not->toContain('-rc.');
});
