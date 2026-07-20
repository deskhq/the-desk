<?php

declare(strict_types=1);

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
