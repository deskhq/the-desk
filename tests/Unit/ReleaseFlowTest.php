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

test('the moving rc alias is gated on the classified tag', function (): void {
    $rule = collect(explode("\n", imageTagRules()))
        ->first(fn (string $line): bool => str_contains($line, 'value=rc,'));

    expect($rule)->toContain("steps.tag.outputs.is_candidate == 'true'");
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

    expect($job['if'])->toContain('refs/heads/master')
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

/**
 * The shell body of the step that decides whether a tag is a candidate.
 */
function tagClassifierScript(): string
{
    $workflow = readWorkflow('docker.yml');

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $workflow['jobs']['build']['steps'];

    foreach ($steps as $step) {
        if (($step['id'] ?? null) === 'tag') {
            return (string) $step['run'];
        }
    }

    throw new RuntimeException('docker.yml has no `tag` step classifying the release tag.');
}

/**
 * Run the classifier against a tag and return the `is_candidate` value it wrote.
 */
function classifyTag(string $tag): string
{
    $output = tempnam(sys_get_temp_dir(), 'gh-output-');

    $process = new Process(
        ['bash', '-c', tagClassifierScript()],
        env: ['TAG' => $tag, 'GITHUB_OUTPUT' => $output],
    );
    $process->mustRun();

    $written = trim((string) file_get_contents((string) $output));
    unlink((string) $output);

    return str_replace('is_candidate=', '', $written);
}

/*
 * A substring test for `-rc.` is not the same predicate as "is a candidate".
 * `v1.12.0+build-rc.1` is a stable release under SemVer, and misreading it would
 * hand a stable release the moving `rc` tag and stamp a candidate warning onto
 * its notes. Only the exact shape release-please cuts counts.
 */
test('a candidate tag is recognised', function (string $tag): void {
    expect(classifyTag($tag))->toBe('true');
})->with(['v1.12.0-rc.0', 'v1.12.0-rc.7', 'v2.0.0-rc.12', 'v10.20.30-rc.400']);

test('anything that is not exactly a candidate tag is treated as stable', function (string $tag): void {
    expect(classifyTag($tag))->toBe('false');
})->with([
    'v1.12.0',
    'v1.12.0+build-rc.1',
    'v1.12.0-rc',
    'v1.12.0-rc.',
    'v1.12.0-rc.1-extra',
    'v1.12.0-beta.1',
    'rc.1',
]);

/*
 * `link-release-image` fires for every `v*` tag, candidates included — which is
 * what we want, since a candidate-tester needs the pull reference more than
 * anyone. But the note it appends reads as an invitation to deploy, so a
 * candidate has to say plainly that it is not supported in production.
 */
test('candidate releases warn against running them in production', function (): void {
    expect(releaseNoteScript())->toContain('not supported for production');
});

/*
 * The notes and the image tags must agree about what a candidate is, so the note
 * step consumes the classification the build job made rather than repeating the
 * test against the tag itself.
 */
test('the release notes reuse the classification the build job made', function (): void {
    expect(readWorkflow('docker.yml')['jobs']['link-release-image']['steps'][0]['env']['IS_CANDIDATE'])
        ->toBe('${{ needs.build.outputs.is_candidate }}');
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
function renderReleaseNote(string $tag, bool $isCandidate): string
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
            'IS_CANDIDATE' => $isCandidate ? 'true' : 'false',
        ],
    );
    $process->mustRun();

    return (string) file_get_contents($sandbox.'/body.md');
}

test('a candidate release note warns before it invites a pull', function (): void {
    $body = renderReleaseNote('v1.12.0-rc.3', isCandidate: true);

    expect($body)
        ->toContain('🚧 Release candidate')
        ->toContain('published for testing ahead of 1.12.0')
        ->toContain('not supported for production')
        ->toContain('ghcr.io/emmpaul/the-desk:1.12.0-rc.3');
});

test('the candidate warning is a section of its own, not glued to the next one', function (): void {
    expect(renderReleaseNote('v1.12.0-rc.3', isCandidate: true))
        ->toContain("releases/latest).\n\n### 📦 Docker image");
});

test('a stable release note carries no candidate warning', function (): void {
    $body = renderReleaseNote('v1.12.0', isCandidate: false);

    expect($body)->not->toContain('Release candidate')
        ->and($body)->toContain('ghcr.io/emmpaul/the-desk:1.12.0')
        ->and($body)->toContain('### 📦 Docker image');
});

test('the note is appended to the notes release-please wrote, not replacing them', function (): void {
    expect(renderReleaseNote('v1.12.0', isCandidate: false))->toStartWith("### Features\n\n* something shipped");
});

test('the release note still links the image for a candidate', function (): void {
    expect(readWorkflow('docker.yml')['jobs']['link-release-image']['if'])
        ->toContain("startsWith(github.ref, 'refs/tags/v')")
        ->not->toContain('-rc.');
});

/**
 * The shell body of the step that moves develop's candidate baseline.
 */
function baselineSyncScript(): string
{
    $workflow = readWorkflow('release-please.yml');

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $workflow['jobs']['sync-candidate-baseline']['steps'];

    foreach ($steps as $step) {
        $run = (string) ($step['run'] ?? '');

        if (str_contains($run, '.release-please-manifest.develop.json')) {
            return $run;
        }
    }

    throw new RuntimeException('release-please.yml has no step moving the candidate baseline.');
}

/*
 * `develop` is created independently of this workflow, so a stable release must
 * not be reported as failed merely because there is no candidate line to update.
 * Every step that touches develop is gated on it actually being there.
 */
test('a stable release succeeds when there is no develop branch', function (): void {
    /** @var array<int, array<string, mixed>> $steps */
    $steps = readWorkflow('release-please.yml')['jobs']['sync-candidate-baseline']['steps'];

    $guarded = collect($steps)->filter(fn (array $step): bool => ($step['id'] ?? null) !== 'develop');

    expect($guarded)->not->toBeEmpty()
        ->and($guarded->every(fn (array $step): bool => ($step['if'] ?? null) === "steps.develop.outputs.exists == 'true'"))
        ->toBeTrue();
});

/**
 * Build a throwaway origin with a `develop` branch, and a clone of it standing in
 * for the workflow's checkout.
 *
 * @return array{0: string, 1: string} the clone and the origin
 */
function developSandbox(string $baseline): array
{
    $root = sys_get_temp_dir().'/baseline-'.bin2hex(random_bytes(6));
    $origin = $root.'/origin';
    $clone = $root.'/clone';
    mkdir($origin, 0o777, true);

    $git = function (string $cwd, string ...$arguments): void {
        (new Process(['git', ...$arguments], $cwd))->mustRun();
    };

    $git($origin, 'init', '--quiet', '--initial-branch=develop');
    $git($origin, 'config', 'user.name', 'Origin');
    $git($origin, 'config', 'user.email', 'origin@example.test');
    $git($origin, 'config', 'receive.denyCurrentBranch', 'updateInstead');
    file_put_contents($origin.'/.release-please-manifest.develop.json', $baseline."\n");
    $git($origin, 'add', '.');
    $git($origin, 'commit', '--quiet', '-m', 'chore: seed');

    $git($root, 'clone', '--quiet', $origin, $clone);

    return [$clone, $origin];
}

/**
 * A `gh` stub that records every call and answers the reads the release workflow
 * makes: the branch listing, the develop-to-master comparison, and the lookup for
 * an already open pull request.
 *
 * @param  list<string>|null  $branches  branches to list, or null to make `gh` fail
 * @param  int  $ahead  commits `master` is ahead of `develop` by
 * @param  string  $existing  number of an already open pull request, or none
 * @return string the sandbox: `bin/gh` is the stub, `calls` its log
 */
function ghSandbox(?array $branches = ['master', 'develop'], int $ahead = 1, string $existing = ''): string
{
    $sandbox = sys_get_temp_dir().'/gh-'.bin2hex(random_bytes(6));
    mkdir($sandbox.'/bin', 0o777, true);

    $listing = $branches === null
        ? "echo 'gh: API rate limit exceeded' >&2; exit 1"
        : 'printf '."'%s\\n' ".implode(' ', array_map(escapeshellarg(...), $branches));

    // Matched on the subcommand and its first argument only: a pull request body
    // may itself mention branches, so a looser pattern would swallow `pr create`.
    file_put_contents($sandbox.'/bin/gh', <<<BASH
        #!/usr/bin/env bash
        printf '%s\\n' "\$*" >> '{$sandbox}/calls'
        case "\$1 \${2-}" in
            'api '*/branches) {$listing} ;;
            'api '*/compare/*) echo '{$ahead}' ;;
            'pr list') printf '%s' '{$existing}' ;;
        esac
        BASH);
    chmod($sandbox.'/bin/gh', 0o755);

    return $sandbox;
}

/**
 * Every `gh` invocation the stub saw, one per line.
 */
function ghCalls(string $sandbox): string
{
    $calls = $sandbox.'/calls';

    return file_exists($calls) ? (string) file_get_contents($calls) : '';
}

/**
 * @param  string  $sandbox  a {@see ghSandbox()} the script's `gh` calls land in
 */
function runBaselineSync(string $clone, string $version, string $sandbox): Process
{
    $process = new Process(
        ['bash', '-c', baselineSyncScript()],
        $clone,
        env: [
            'PATH' => $sandbox.'/bin:'.getenv('PATH'),
            'REPO' => 'emmpaul/the-desk',
            'GH_TOKEN' => 'stub',
            'VERSION' => $version,
        ],
    );
    $process->run();

    return $process;
}

function baselineOf(string $repository): string
{
    return trim((string) file_get_contents($repository.'/.release-please-manifest.develop.json'));
}

/**
 * The baseline as it stands on a branch of the throwaway origin.
 */
function baselineOnBranch(string $origin, string $branch): string
{
    return trim((new Process(['git', 'show', $branch.':.release-please-manifest.develop.json'], $origin))->mustRun()->getOutput());
}

/**
 * Run the develop-existence check against a stubbed `gh` that either lists the
 * repository's branches or fails, and return the step's outcome.
 *
 * @param  list<string>|null  $branches  branches to list, or null to make `gh` fail
 * @return array{exists: string|null, succeeded: bool}
 */
function checkDevelopExists(?array $branches): array
{
    $sandbox = sys_get_temp_dir().'/develop-check-'.bin2hex(random_bytes(6));
    mkdir($sandbox.'/bin', 0o777, true);

    $stub = $branches === null
        ? "#!/usr/bin/env bash\necho 'gh: API rate limit exceeded' >&2\nexit 1\n"
        : "#!/usr/bin/env bash\nprintf '%s\\n' ".implode(' ', array_map(escapeshellarg(...), $branches))."\n";

    file_put_contents($sandbox.'/bin/gh', $stub);
    chmod($sandbox.'/bin/gh', 0o755);

    $output = $sandbox.'/output';
    touch($output);

    $workflow = readWorkflow('release-please.yml');

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $workflow['jobs']['sync-candidate-baseline']['steps'];

    $script = (string) collect($steps)->firstWhere('id', 'develop')['run'];

    $process = new Process(
        ['bash', '-c', $script],
        env: [
            'PATH' => $sandbox.'/bin:'.getenv('PATH'),
            'REPO' => 'emmpaul/the-desk',
            'GH_TOKEN' => 'stub',
            'GITHUB_OUTPUT' => $output,
        ],
    );
    $process->run();

    $written = trim((string) file_get_contents($output));

    return [
        'exists' => $written === '' ? null : str_replace('exists=', '', $written),
        'succeeded' => $process->isSuccessful(),
    ];
}

test('develop is found when the branch listing contains it', function (): void {
    expect(checkDevelopExists(['master', 'develop', 'feat/thing']))
        ->toMatchArray(['exists' => 'true', 'succeeded' => true]);
});

test('develop is absent when a successful listing does not contain it', function (): void {
    expect(checkDevelopExists(['master', 'feat/thing']))
        ->toMatchArray(['exists' => 'false', 'succeeded' => true]);
});

/*
 * The failure mode this guards against: probing `branches/develop` directly
 * answers 404 both when the branch is absent and when the call was refused, so a
 * token or API problem would read as "no develop" and skip the baseline sync
 * without anyone noticing. An unreadable listing has to fail the step instead.
 */
test('an unreadable branch listing fails loudly rather than reporting develop absent', function (): void {
    $result = checkDevelopExists(null);

    expect($result['succeeded'])->toBeFalse()
        ->and($result['exists'])->not->toBe('false');
});

test('a stable release proposes the new candidate baseline to develop', function (): void {
    [$clone, $origin] = developSandbox('{".":"1.11.0"}');
    $sandbox = ghSandbox();

    $process = runBaselineSync($clone, '1.12.0', $sandbox);

    expect($process->isSuccessful())->toBeTrue()
        ->and(baselineOnBranch($origin, 'chore/candidate-baseline-1.12.0'))->toBe('{".":"1.12.0"}')
        ->and(ghCalls($sandbox))->toContain('pr create')
        ->toContain('--base develop')
        ->toContain('--head chore/candidate-baseline-1.12.0')
        // The title is what release-please would read off the squashed commit, so
        // it has to parse as a Conventional Commit like any other PR title.
        ->toContain('chore: move the candidate baseline to 1.12.0');
});

/*
 * The failure this guards against, seen in production at 1.12.0: `develop` is
 * covered by a ruleset requiring pull requests, so a direct push is rejected with
 * GH013 and the whole stable release run goes red *after* the release has already
 * been tagged and published. The baseline has to travel the same way every other
 * change to develop does.
 */
test('the candidate baseline is never pushed straight to develop', function (): void {
    [$clone, $origin] = developSandbox('{".":"1.11.0"}');

    $process = runBaselineSync($clone, '1.12.0', ghSandbox());

    expect($process->isSuccessful())->toBeTrue()
        ->and(baselineOf($origin))->toBe('{".":"1.11.0"}')
        ->and(baselineSyncScript())->not->toContain('push origin develop');
});

/*
 * Nothing merges the baseline PR on its own, and a release must not wait on a
 * human to notice it — so auto-merge is asked for, and a repository that cannot
 * grant it says so rather than failing the release.
 */
test('the baseline PR is queued to merge itself', function (): void {
    [$clone] = developSandbox('{".":"1.11.0"}');
    $sandbox = ghSandbox();

    runBaselineSync($clone, '1.12.0', $sandbox);

    expect(ghCalls($sandbox))->toContain('pr merge')
        ->toContain('--auto');
});

test('moving the baseline twice is a no-op the second time', function (): void {
    [$clone, $origin] = developSandbox('{".":"1.12.0"}');
    $sandbox = ghSandbox();

    $process = runBaselineSync($clone, '1.12.0', $sandbox);

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('already 1.12.0')
        ->and(baselineOf($origin))->toBe('{".":"1.12.0"}')
        ->and(ghCalls($sandbox))->not->toContain('pr create');
});

/*
 * A baseline PR left open from an earlier release must be updated in place rather
 * than joined by a second one: two open PRs writing the same one-line file would
 * conflict with each other.
 */
test('an open baseline PR is updated rather than duplicated', function (): void {
    [$clone, $origin] = developSandbox('{".":"1.11.0"}');
    $sandbox = ghSandbox(existing: '7');

    $process = runBaselineSync($clone, '1.12.0', $sandbox);

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('#7')
        ->and(baselineOnBranch($origin, 'chore/candidate-baseline-1.12.0'))->toBe('{".":"1.12.0"}')
        ->and(ghCalls($sandbox))->not->toContain('pr create')
        // The run that opened it may have been unable to queue auto-merge, so a
        // reused PR is asked again rather than left for someone to notice.
        ->toContain('pr merge');
});

/*
 * The lookup has to be pinned to this job's own branch: an unscoped one would
 * find any open PR against develop and conclude the baseline was already
 * proposed.
 */
test('an open baseline PR is looked for on the branch it would be opened from', function (): void {
    [$clone] = developSandbox('{".":"1.11.0"}');
    $sandbox = ghSandbox();

    runBaselineSync($clone, '1.12.0', $sandbox);

    expect(collect(explode("\n", ghCalls($sandbox)))->first(fn (string $call): bool => str_starts_with($call, 'pr list')))
        ->toContain('--base develop')
        ->toContain('--head chore/candidate-baseline-1.12.0')
        ->toContain('--state open');
});

/*
 * develop keeps moving while a release is cut. Re-deriving the baseline on top of
 * whatever landed is always correct because the value is absolute, not a delta —
 * which is why the branch is cut fresh from `origin/develop` rather than from the
 * checkout the job started with.
 */
test('the baseline is proposed on top of whatever develop has moved to', function (): void {
    [$clone, $origin] = developSandbox('{".":"1.11.0"}');

    // Land an unrelated commit on origin *after* the clone, exactly as a merge to
    // develop during the release would.
    file_put_contents($origin.'/somebody-elses-work.txt', "meanwhile\n");
    (new Process(['git', 'add', '.'], $origin))->mustRun();
    (new Process(['git', 'commit', '--quiet', '-m', 'feat: land something else'], $origin))->mustRun();

    $process = runBaselineSync($clone, '1.12.0', ghSandbox());
    $branch = 'chore/candidate-baseline-1.12.0';

    expect($process->isSuccessful())->toBeTrue()
        ->and(baselineOnBranch($origin, $branch))->toBe('{".":"1.12.0"}')
        ->and((new Process(['git', 'show', $branch.':somebody-elses-work.txt'], $origin))->run())->toBe(0);
});

/*
 * A hotfix released straight from `master` exists only there. The next
 * `develop` -> `master` promotion carries an older ancestor of the same files, so
 * without a back-merge it silently reverts the fix and production breaks a second
 * time for no visible reason. The job below is what makes that step unmissable.
 */
test('a stable release opens a back-merge into develop', function (): void {
    $job = readWorkflow('release-please.yml')['jobs']['backmerge'];

    expect($job['if'])->toContain("needs.release-please.outputs.release_created == 'true'")
        ->and($job['needs'])->toContain('release-please');
});

/*
 * The two jobs touch different things — one proposes a one-line manifest bump,
 * the other proposes a merge — so ordering them buys nothing, and chaining them
 * would let a failing baseline sync suppress the back-merge in exactly the
 * release where a hotfix makes it matter.
 */
test('the back-merge does not wait on the candidate baseline', function (): void {
    expect(readWorkflow('release-please.yml')['jobs']['backmerge']['needs'])
        ->not->toContain('sync-candidate-baseline');
});

/**
 * The shell body of the step that opens the `master` -> `develop` back-merge PR.
 */
function backmergeScript(): string
{
    $workflow = readWorkflow('release-please.yml');

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $workflow['jobs']['backmerge']['steps'];

    foreach ($steps as $step) {
        $run = (string) ($step['run'] ?? '');

        if (str_contains($run, 'gh pr create')) {
            return $run;
        }
    }

    throw new RuntimeException('release-please.yml has no step opening the back-merge PR.');
}

/**
 * Run the back-merge step against a stubbed `gh`, and report what it did.
 *
 * @param  list<string>|null  $branches  branches to list, or null to make `gh` fail
 * @param  int  $ahead  commits `master` is ahead of `develop` by
 * @param  string  $existing  number of an already open back-merge PR, or none
 * @return array{created: string|null, output: string, errors: string, succeeded: bool}
 */
function runBackmerge(?array $branches, int $ahead = 1, string $existing = ''): array
{
    $sandbox = ghSandbox($branches, $ahead, $existing);

    $process = new Process(
        ['bash', '-c', backmergeScript()],
        env: [
            'PATH' => $sandbox.'/bin:'.getenv('PATH'),
            'REPO' => 'emmpaul/the-desk',
            'GH_TOKEN' => 'stub',
            'VERSION' => '1.12.1',
        ],
    );
    $process->run();

    $calls = ghCalls($sandbox);
    $created = collect(explode("\n", $calls))->first(fn (string $call): bool => str_starts_with($call, 'pr create'));

    return [
        'created' => $created,
        'output' => $process->getOutput(),
        'errors' => $process->getErrorOutput(),
        'succeeded' => $process->isSuccessful(),
    ];
}

test('a released master is proposed for merge back into develop', function (): void {
    $result = runBackmerge(['master', 'develop']);

    expect($result['succeeded'])->toBeTrue()
        ->and($result['created'])->toContain('--base develop')
        ->toContain('--head master')
        // The PR title is the one release-please would read if this were ever
        // squashed, so it has to parse as a Conventional Commit like any other.
        ->toContain('chore: merge master back into develop after 1.12.1');
});

test('nothing is opened when develop already contains master', function (): void {
    $result = runBackmerge(['master', 'develop'], ahead: 0);

    expect($result['succeeded'])->toBeTrue()
        ->and($result['created'])->toBeNull()
        ->and($result['output'])->toContain('already contains master');
});

test('an open back-merge is left to track master rather than duplicated', function (): void {
    $result = runBackmerge(['master', 'develop'], existing: '42');

    expect($result['succeeded'])->toBeTrue()
        ->and($result['created'])->toBeNull()
        ->and($result['output'])->toContain('#42');
});

test('a stable release succeeds when there is no develop branch to back-merge into', function (): void {
    $result = runBackmerge(['master']);

    expect($result['succeeded'])->toBeTrue()
        ->and($result['created'])->toBeNull();
});

/*
 * Same failure mode the baseline sync guards against: a refused API call must not
 * read as "no develop" and skip the back-merge silently.
 */
test('an unreadable branch listing fails the back-merge loudly', function (): void {
    $result = runBackmerge(null);

    expect($result['succeeded'])->toBeFalse()
        ->and($result['created'])->toBeNull()
        ->and($result['errors'])->toContain('rate limit');
});

/*
 * Squashing a back-merge would flatten master's history into a single new commit
 * on develop, leaving the branches permanently diverged and every later
 * back-merge conflicting. The instruction has to travel with the PR.
 */
test('the back-merge PR says it must not be squashed', function (): void {
    expect(backmergeScript())->toContain('merge commit')
        ->toContain('squash');
});
