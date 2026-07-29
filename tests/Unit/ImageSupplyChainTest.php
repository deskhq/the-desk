<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

/**
 * The published image is the artifact self-hosters actually run, and nothing in
 * this repository changes when a CVE lands in its base image or OS packages. So
 * the supply chain around it is guarded here rather than left to review (#836):
 * every published image carries provenance and an SBOM, every build is scanned,
 * a scheduled scan catches what is disclosed between releases, and the release
 * notes offer a digest an operator can pin to.
 *
 * These are file assertions, not a CI run — the workflows themselves only ever
 * execute on GitHub. What they buy is that a step deleted or renamed in passing
 * fails here instead of silently un-hardening the image.
 */
function supplyChainWorkflow(string $name): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/'.$name.'.yml');
}

/**
 * The `on:` block of a workflow. YAML 1.1 reads a bare `on` as the boolean key
 * `true`, which is what Symfony's parser follows.
 */
function supplyChainTriggers(array $workflow): array
{
    return $workflow[true] ?? $workflow['on'];
}

function supplyChainBuildAction(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/.github/actions/docker-build/action.yml');
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function supplyChainSteps(string $workflow, string $job): Collection
{
    return collect(supplyChainWorkflow($workflow)['jobs'][$job]['steps'] ?? []);
}

/**
 * Every call into the composite action made by the given job.
 *
 * @return Collection<int, array<string, mixed>>
 */
function supplyChainImageBuilds(string $workflow, string $job): Collection
{
    return supplyChainSteps($workflow, $job)
        ->filter(static fn (array $step): bool => ($step['uses'] ?? null) === './.github/actions/docker-build');
}

function supplyChainStepUsing(string $workflow, string $job, string $action): ?array
{
    return supplyChainSteps($workflow, $job)
        ->first(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), $action));
}

/**
 * Every `run:` script in a job, concatenated — the shell is where the rolling
 * issue and the release-note editing actually live.
 */
function supplyChainRunScripts(string $workflow, string $job): string
{
    return supplyChainSteps($workflow, $job)->pluck('run')->filter()->implode("\n");
}

/**
 * The production Dockerfile as text. Whatever the base image ships, this file is
 * the only place this repository gets to patch it.
 */
function supplyChainDockerfile(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
}

/**
 * Path to the Trivy ignore file both scans read.
 */
function supplyChainIgnoreFile(): string
{
    return dirname(__DIR__, 2).'/.trivyignore.yaml';
}

/**
 * Every suppressed finding, with `expired_at` kept as a date rather than folded
 * to a Unix timestamp (which is what the parser does without the flag).
 *
 * @return list<array<string, mixed>>
 */
function supplyChainSuppressions(): array
{
    return Yaml::parseFile(supplyChainIgnoreFile(), Yaml::PARSE_DATETIME)['vulnerabilities'] ?? [];
}

test('the composite action can attach provenance and an SBOM to what it pushes', function (): void {
    $action = supplyChainBuildAction();

    expect($action['inputs'])->toHaveKeys(['provenance', 'sbom']);

    // Off unless a call site asks for them: the docker exporter cannot carry an
    // attestation, so a `load: true` build fails outright if either is on.
    expect($action['inputs']['provenance']['default'] ?? null)->toBe('false')
        ->and($action['inputs']['sbom']['default'] ?? null)->toBe('false');
});

test('the composite action reports the digest of whichever path built the image', function (): void {
    $value = supplyChainBuildAction()['outputs']['digest']['value'] ?? '';

    // A skipped step contributes no output, so the two provider paths are
    // coalesced rather than picked between — the caller cannot know which ran,
    // which is what the `||` is doing there.
    expect($value)
        ->toContain('.outputs.digest')
        ->toContain('||');
});

test('both provider paths attach the same attestations', function (string $input): void {
    $builds = collect(supplyChainBuildAction()['runs']['steps'])
        ->filter(static fn (array $step): bool => str_contains((string) ($step['uses'] ?? ''), '/build-push-action@'));

    expect($builds)->toHaveCount(2);

    foreach ($builds as $build) {
        expect($build['with'][$input] ?? null)
            ->toBe('${{ inputs.'.$input.' }}', $build['uses'].' drops the '.$input.' input, so the published image would depend on which fleet built it');
    }
})->with(['provenance', 'sbom']);

test('the published image is built with provenance and an SBOM', function (): void {
    $publish = supplyChainImageBuilds('docker', 'build')
        ->first(static fn (array $step): bool => ($step['with']['push'] ?? false) === true);

    expect($publish)->not->toBeNull('the publish path must still build through the composite action');

    // `mode=max` rather than the default `mode=min`: min records only the
    // materials, which tells an operator nothing about how the image was built.
    expect($publish['with']['provenance'] ?? null)->toBe('mode=max')
        ->and($publish['with']['sbom'] ?? null)->toBeTrue();
});

test('no build that loads into the local daemon asks for an attestation', function (): void {
    $loads = collect(supplyChainWorkflow('docker')['jobs'])
        ->flatMap(static fn (array $job): array => $job['steps'] ?? [])
        ->filter(static fn (array $step): bool => ($step['uses'] ?? null) === './.github/actions/docker-build')
        ->filter(static fn (array $step): bool => ($step['with']['load'] ?? false) === true);

    expect($loads)->not->toBeEmpty('the backup/upgrade jobs still build into the daemon');

    // The docker exporter has no way to represent an attestation, so buildx
    // fails the build rather than dropping it. Keeping these off is what lets
    // the publish path turn them on.
    foreach ($loads as $step) {
        expect($step['with']['provenance'] ?? 'false')->toBe('false')
            ->and($step['with']['sbom'] ?? 'false')->toBe('false');
    }
});

test('the publish job attests the pushed image to the registry', function (): void {
    $attest = supplyChainStepUsing('docker', 'build', 'actions/attest-build-provenance@');

    expect($attest)->not->toBeNull('nothing produces an attestation verifiable with `gh attestation verify`');

    expect($attest['with']['subject-name'] ?? null)->toBe('ghcr.io/${{ github.repository }}')
        ->and($attest['with']['subject-digest'] ?? '')->toContain('.outputs.digest')
        // Without this the attestation only lives in the API, so an operator
        // pulling the image has nothing attached to inspect.
        ->and($attest['with']['push-to-registry'] ?? null)->toBeTrue();
});

test('the publish job holds the scopes attesting and scanning need', function (): void {
    $permissions = supplyChainWorkflow('docker')['jobs']['build']['permissions'];

    expect($permissions)
        ->toHaveKey('packages', 'write')
        // The attestation is signed through OIDC and recorded against the repo.
        ->toHaveKey('id-token', 'write')
        ->toHaveKey('attestations', 'write')
        // The scan below reports into the code-scanning tab.
        ->toHaveKey('security-events', 'write');
});

test('the build-only path loads the image so there is something to scan', function (): void {
    $build = supplyChainImageBuilds('docker', 'build')
        ->first(static fn (array $step): bool => ($step['with']['push'] ?? false) !== true);

    expect($build)->not->toBeNull()
        // Without this the image only ever exists in the builder's cache, and
        // the scanner has nothing to point at.
        ->and($build['with']['load'] ?? null)->toBeTrue()
        ->and($build['with']['tags'] ?? null)->toBe('the-desk:ci');
});

test('the build-only path scans the image it just built', function (): void {
    $scans = supplyChainSteps('docker', 'build')
        ->filter(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'aquasecurity/trivy-action@'));

    expect($scans)->not->toBeEmpty('nothing scans the freshly built image');

    foreach ($scans as $scan) {
        expect($scan['with']['image-ref'] ?? null)->toBe('the-desk:ci')
            // Non-blocking for now: the point is visibility, and a base-image
            // CVE with no fix available must not red a PR that changed nothing.
            ->and((string) ($scan['with']['exit-code'] ?? '0'))->toBe('0');
    }

    $sarif = supplyChainStepUsing('docker', 'build', 'github/codeql-action/upload-sarif@');
    expect($sarif)->not->toBeNull('the findings must reach the code-scanning tab, where they dedupe and carry history');

    // A fork's token is read-only whatever the job asks for, so an unguarded
    // upload 403s and reds an external contributor's pull request.
    expect($sarif['if'] ?? '')->toContain('github.event.pull_request.head.repo.full_name == github.repository');

    // Readable on the run itself, not only behind the Security tab, which most
    // reviewers never open on a PR.
    expect(supplyChainRunScripts('docker', 'build'))->toContain('GITHUB_STEP_SUMMARY');
});

test('the runtime stage patches the base image OS packages at build time', function (): void {
    $dockerfile = supplyChainDockerfile();
    $runtimeStart = strpos($dockerfile, 'AS runtime');

    // Without this the stage marker could be renamed and `substr` would fall
    // back to offset 0, quietly widening every assertion below to the whole
    // file — so an `apk upgrade` in a build stage that ships nothing would pass.
    expect($runtimeStart)->not->toBeFalse('the runtime stage is what ships, and it can no longer be located');

    $runtime = substr($dockerfile, (int) $runtimeStart);

    // A base image carries the apk snapshot it was built against, and Alpine
    // publishes security updates to the branch repository continuously — so an
    // OS package goes stale in the published image without a single commit
    // landing here (#962, c-ares CVE-2026-33630, fixed upstream but not yet in
    // any FrankenPHP build). Upgrading at build time is what closes that window.
    // In the runtime stage specifically: the vendor and assets stages contribute
    // no packages to the image that ships.
    expect($runtime)->toContain('apk upgrade --no-cache');

    // Under the same bounded retry as every other apk call in the stage, since
    // it reaches the network exactly the same way (#626, #641).
    expect($runtime)->toContain('retry apk upgrade --no-cache');
});

test('every suppressed vulnerability is justified, scoped, and expires', function (): void {
    // The list is allowed to be empty — nothing suppressed is strictly better —
    // but an entry that is on it has to earn its place.
    foreach (supplyChainSuppressions() as $entry) {
        expect($entry['id'] ?? '')->toBeString()->not->toBeEmpty();

        // A suppression nobody can read back is indistinguishable from one added
        // to turn a red scan green.
        expect($entry['statement'] ?? '')->toBeString()->not->toBeEmpty();

        // Scoped to the artifact it was assessed against, so the same CVE
        // surfacing in something this repository does control is still reported.
        // A bare scalar reads like a scope but is not one Trivy accepts, so the
        // shape is asserted rather than just the emptiness.
        expect($entry['paths'] ?? null)->toBeArray()->not->toBeEmpty();

        foreach ($entry['paths'] as $path) {
            expect($path)->toBeString()->not->toBeEmpty();
        }

        // And never permanent: Trivy reports an expired entry again, which
        // reopens the rolling issue rather than letting the finding disappear.
        expect($entry['expired_at'] ?? null)->toBeInstanceOf(DateTimeInterface::class);
    }
});

test('both scans read the same checked-in suppression list', function (): void {
    expect(supplyChainIgnoreFile())->toBeFile();

    // Every trivy-action call on the build path, or the per-build scan and the
    // weekly one disagree about what counts as a finding.
    $scans = supplyChainSteps('docker', 'build')
        ->filter(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'aquasecurity/trivy-action@'));

    expect($scans)->not->toBeEmpty();

    foreach ($scans as $scan) {
        expect($scan['with']['trivyignores'] ?? null)->toBe('.trivyignore.yaml');
    }

    // The weekly scan only ever talked to a registry, so the file it now has to
    // read has to be fetched first — and fetched *before* the scan runs, which
    // is the half of it a "the step exists" assertion would miss.
    $steps = supplyChainSteps('image-scan', 'scan-published-images');
    $checkout = $steps->search(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@'));
    $scan = $steps->search(static fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'trivy image'));

    expect($checkout)->not->toBeFalse('the weekly scan cannot read the ignore file without checking the repository out')
        ->and($scan)->not->toBeFalse()
        ->and($checkout)->toBeLessThan($scan);

    // The path too, not just the flag: pointed at a file that is not there,
    // Trivy scans on with no suppressions and the rolling issue never closes.
    expect(supplyChainRunScripts('image-scan', 'scan-published-images'))
        ->toContain('--ignorefile .trivyignore.yaml');

    // Editing the list changes what the build scan reports, so a pull request
    // that touches it has to rebuild and re-scan rather than merge on the
    // strength of the diff.
    expect(supplyChainTriggers(supplyChainWorkflow('docker'))['pull_request']['paths'] ?? [])
        ->toContain('.trivyignore.yaml');
});

test('the build job publishes the digest of the image it pushed', function (): void {
    $outputs = supplyChainWorkflow('docker')['jobs']['build']['outputs'];

    // The release-note job below can only pin by digest if the build hands it
    // one; the push is the only place it exists.
    expect($outputs['digest'] ?? '')->toContain('.outputs.digest');
});

test('the release notes offer an immutable digest reference beside the tag', function (): void {
    $job = supplyChainWorkflow('docker')['jobs']['link-release-image'];
    $step = collect($job['steps'])->firstWhere(fn (array $step): bool => isset($step['run']));

    expect($step['env']['DIGEST'] ?? null)->toBe('${{ needs.build.outputs.digest }}');

    $run = (string) $step['run'];

    // Tags are mutable; the digest is the only reference that cannot be moved
    // under an operator after they pin it.
    expect($run)
        ->toContain('${REPO}@${DIGEST}')
        // Still one appended block, still guarded by the marker: a re-run of the
        // publish must not append a second copy.
        ->toContain('ghcr-image-link')
        ->toContain('grep -qF "$marker"');
});

test('a scheduled scan watches the images that are already published', function (): void {
    $triggers = supplyChainTriggers(supplyChainWorkflow('image-scan'));

    expect($triggers['schedule'][0]['cron'] ?? null)->toBeString()
        // On demand too, so a disclosure can be checked without waiting a week.
        ->and(array_key_exists('workflow_dispatch', $triggers))->toBeTrue();

    $job = supplyChainWorkflow('image-scan')['jobs']['scan-published-images'];

    expect($job['permissions']['issues'] ?? null)->toBe('write', 'the scan reports by opening an issue');

    $step = collect($job['steps'])->last(fn (array $step): bool => isset($step['run']));

    // Tracks the repository rather than a literal name, so a rename does not
    // leave the scan pointed at a package that no longer moves.
    expect($step['env']['IMAGE'] ?? null)->toBe('ghcr.io/${{ github.repository }}');

    $run = supplyChainRunScripts('image-scan', 'scan-published-images');

    // Both moving tags: `latest` is what most operators run, `rc` is what the
    // candidate testers run, and a CVE can reach either first.
    expect($run)
        ->toContain('latest')
        ->toContain('rc');

    // One rolling issue, like extension-pins.yml: comment while it stays dirty,
    // close once it is clean, so a current repo has no stale reminder open.
    expect($run)
        ->toContain('gh issue list')
        ->toContain('gh issue create')
        ->toContain('gh issue comment')
        ->toContain('gh issue close');
});

test('the self-hosting docs explain how to verify and pin the image', function (string $page): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2).'/docs/src/content/docs/'.$page);

    expect($contents)
        ->toContain('gh attestation verify')
        ->toContain('ghcr.io/deskhq/the-desk@sha256:');
})->with(['self-hosting/installation.md', 'self-hosting/upgrading.md']);
