<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

/**
 * A bounded retry cannot ride out a multi-hour pecl.php.net outage, and
 * `Upgrade script (build from source)` builds cold and uncached on purpose, so
 * the retry is its only shield (issue #641). The image therefore resolves
 * nothing through PECL: the two remote extensions are built from pinned GitHub
 * sources, which no PECL outage can touch. Pins that no one bumps are their own
 * defect, so a scheduled workflow has to flag a stale one.
 */
function productionDockerfile(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
}

/**
 * @return array<string, string>
 */
function extensionPins(): array
{
    preg_match_all('/^ARG (PHPREDIS_VERSION|IMAGICK_VERSION)=(\S+)$/m', productionDockerfile(), $matches, PREG_SET_ORDER);

    return array_column($matches, 2, 1);
}

test('both remote extensions are pinned to a concrete released version', function (): void {
    $pins = extensionPins();

    expect($pins)->toHaveKeys(['PHPREDIS_VERSION', 'IMAGICK_VERSION']);

    foreach ($pins as $name => $version) {
        expect($version)->toMatch('/^\d+\.\d+\.\d+$/', "$name must pin an exact release, not a branch or a range");
    }
});

test('neither redis nor imagick is requested by the name that resolves through pecl', function (): void {
    $arguments = collect(explode("\n", productionDockerfile()))
        ->map(trim(...))
        ->reject(static fn (string $line): bool => str_starts_with($line, '#'))
        ->map(static fn (string $line): string => rtrim($line, '\\ '))
        ->filter();

    foreach (['redis', 'imagick'] as $module) {
        expect($arguments)->not->toContain($module, "a bare `$module` argument is fetched from pecl.php.net");
    }
});

test('phpredis is built from its git tag with the submodule it needs', function (): void {
    $contents = productionDockerfile();

    // The codeload tarball omits the liblzf submodule, so the source has to come
    // from a clone that carries submodules rather than an archive.
    expect($contents)
        ->toContain('https://github.com/phpredis/phpredis.git')
        ->toContain('--branch "$PHPREDIS_VERSION"')
        ->toContain('--recurse-submodules');

    expect($contents)->toMatch(
        '/install-php-extensions(?:[^\n]|\\\\\n)*\s\/tmp\/phpredis(\s|\\\\)/',
        'the cloned source directory must be handed to install-php-extensions instead of the module name',
    );
});

test('imagick is built from its GitHub release archive', function (): void {
    expect(productionDockerfile())->toContain(
        'https://github.com/Imagick/imagick/archive/refs/tags/${IMAGICK_VERSION}.tar.gz',
    );
});

test('a scheduled workflow flags a pin that has fallen behind upstream', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/extension-pins.yml');

    // `on:` parses as the boolean key `true` in YAML 1.1, which is what Symfony's
    // parser follows.
    $triggers = $workflow[true] ?? $workflow['on'];

    expect($triggers)->toHaveKey('schedule')
        ->and($triggers['schedule'][0]['cron'] ?? null)->toBeString()
        // On demand too, so a stale pin can be checked without waiting a month.
        ->and(array_key_exists('workflow_dispatch', $triggers))->toBeTrue();

    $job = $workflow['jobs']['check-pins'];

    expect($job['permissions']['issues'] ?? null)->toBe('write', 'the check reports by opening an issue');

    $run = collect($job['steps'])->pluck('run')->filter()->implode("\n");

    expect($run)
        ->toContain('phpredis/phpredis')
        ->toContain('Imagick/imagick')
        ->toContain('PHPREDIS_VERSION')
        ->toContain('IMAGICK_VERSION')
        ->toContain('gh issue create');
});
