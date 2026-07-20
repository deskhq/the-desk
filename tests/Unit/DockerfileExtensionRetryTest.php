<?php

declare(strict_types=1);

/**
 * `install-php-extensions` fetches `redis` from pecl.php.net on every image build,
 * so a PECL outage reds the whole Docker workflow on a commit that changed nothing
 * (issue #626). The fetch must be wrapped in a bounded retry: a transient failure
 * clears on a later attempt, while a genuine one (a missing or incompatible
 * extension) still fails after a capped number of tries and a capped total wait.
 */
function dockerfileContents(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
}

test('every install-php-extensions invocation is wrapped in a retry loop', function (): void {
    $invocations = array_filter(
        array_map(trim(...), explode("\n", dockerfileContents())),
        static fn (string $line): bool => str_contains($line, 'install-php-extensions') && ! str_starts_with($line, '#'),
    );

    expect($invocations)->not->toBeEmpty();

    foreach ($invocations as $line) {
        expect($line)->toStartWith('until install-php-extensions');
    }
});

test('the extension install retries a bounded number of times with a bounded wait', function (): void {
    $contents = dockerfileContents();

    expect(preg_match('/max_attempts=(\d+)/', $contents, $attempts))->toBe(1, 'the retry must cap its attempts');
    expect(preg_match('/retry_delay=(\d+)/', $contents, $delay))->toBe(1, 'the retry must cap its backoff');

    $maxAttempts = (int) $attempts[1];
    $baseDelay = (int) $delay[1];

    expect($maxAttempts)->toBeGreaterThan(1)->toBeLessThanOrEqual(5);

    $totalWait = array_sum(array_map(
        static fn (int $attempt): int => $attempt * $baseDelay,
        range(1, $maxAttempts - 1),
    ));

    expect($totalWait)->toBeLessThanOrEqual(60, 'a genuine failure must not hang the build for minutes');
});

test('the retry gives up with a legible message instead of looping forever', function (): void {
    expect(dockerfileContents())->toContain('failed after');
});

test('the installed extension list is unchanged', function (): void {
    expect(preg_match('/install-php-extensions((?:\s+\\\\\s+[a-z_0-9]+)+)/', dockerfileContents(), $matches))->toBe(1);

    $extensions = preg_split('/[\s\\\\]+/', trim($matches[1]), flags: PREG_SPLIT_NO_EMPTY);

    expect($extensions)->toBe([
        'pdo_pgsql',
        'redis',
        'pcntl',
        'posix',
        'intl',
        'zip',
        'opcache',
        'gd',
        'imagick',
        'ldap',
    ]);
});
