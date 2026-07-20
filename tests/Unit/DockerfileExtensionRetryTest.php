<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

/**
 * Building the extensions reaches the network — GitHub, Alpine's mirrors — so a
 * transient failure must not red a build on a commit that changed nothing
 * (issue #626). Every fetch is wrapped in a bounded retry: a transient failure
 * clears on a later attempt, while a genuine one (a missing or incompatible
 * extension) still fails after a capped number of tries and a capped total wait.
 * The source of those extensions is covered by DockerfileExtensionPinsTest.
 */
function dockerfileContents(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
}

test('every network fetch in the runtime stage is wrapped in the retry helper', function (): void {
    $runtimeStage = substr(dockerfileContents(), (int) strpos(dockerfileContents(), 'AS runtime'));

    $fetches = array_filter(
        array_map(trim(...), explode("\n", $runtimeStage)),
        static fn (string $line): bool => (bool) preg_match('/^\S*\s*(install-php-extensions|git clone|apk add)/', $line)
            && ! str_starts_with($line, '#'),
    );

    expect($fetches)->not->toBeEmpty();

    foreach ($fetches as $line) {
        expect($line)->toStartWith('retry ');
    }
});

test('the extension install retries a bounded number of times with a bounded wait', function (): void {
    $contents = dockerfileContents();

    expect(preg_match('/max_attempts=(\d+)/', $contents, $attempts))->toBe(1, 'the retry must cap its attempts');
    expect(preg_match('/retry_delay=(\d+)/', $contents, $delay))->toBe(1, 'the retry must cap its backoff');

    $maxAttempts = (int) $attempts[1];
    $baseDelay = (int) $delay[1];

    expect($maxAttempts)->toBeGreaterThan(1)->toBeLessThanOrEqual(5)
        ->and($baseDelay)->toBeGreaterThan(0, 'a zero backoff would hammer an outage rather than ride it out');

    $totalWait = array_sum(array_map(
        static fn (int $attempt): int => $attempt * $baseDelay,
        range(1, $maxAttempts - 1),
    ));

    expect($totalWait)->toBeLessThanOrEqual(120, 'a genuine failure must not hang the build for minutes');
});

test('the retry loop advances, backs off, and gives up with a legible message', function (): void {
    $contents = dockerfileContents();

    expect($contents)
        ->toContain('attempt=$((attempt + 1))')
        ->toContain('delay=$((attempt * retry_delay))')
        ->toContain('sleep "$delay"')
        ->toContain('if [ "$attempt" -ge "$max_attempts" ]; then')
        ->toContain('exit 1')
        ->toMatch('/echo "[^"]*failed after \$max_attempts attempts[^"]*" >&2/');

    expect(strpos($contents, 'if [ "$attempt" -ge "$max_attempts" ]; then'))
        ->toBeLessThan(strpos($contents, 'sleep "$delay"'), 'the last attempt must give up, not sleep first');
});

test('the build-only validation caches its layers so PECL is not refetched every run', function (): void {
    $steps = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/docker.yml')['jobs']['build']['steps'];

    $build = collect($steps)->firstWhere('name', 'Build production image');

    expect($build)->not->toBeNull()
        ->and($build['uses'] ?? '')->toStartWith('docker/build-push-action@')
        ->and($build['with']['cache-from'] ?? null)->toBe('type=gha')
        ->and($build['with']['cache-to'] ?? '')->toStartWith('type=gha,mode=max')
        ->and($build['with']['push'] ?? null)->toBeFalse();
});

test('the bundled extension list is unchanged', function (): void {
    expect(preg_match('/install-php-extensions((?:\s+\\\\\s+[a-z_0-9]+)+)/', dockerfileContents(), $matches))->toBe(1);

    $extensions = preg_split('/[\s\\\\]+/', trim($matches[1]), flags: PREG_SPLIT_NO_EMPTY);

    // redis and imagick are absent by design: they are the two extensions PHP
    // does not bundle, and they are built from pinned sources further down.
    expect($extensions)->toBe([
        'pdo_pgsql',
        'pcntl',
        'posix',
        'intl',
        'zip',
        'opcache',
        'gd',
        'ldap',
    ]);
});
