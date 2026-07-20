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
 * Every argument handed to `install-php-extensions`, with line continuations
 * folded in so an argument is found wherever it sits on the command.
 *
 * @return list<string>
 */
function installPhpExtensionsArguments(): array
{
    $folded = (string) preg_replace('/\\\\\s*\n\s*/', ' ', productionDockerfile());

    $arguments = [];

    foreach (explode("\n", $folded) as $line) {
        $line = trim($line);
        $position = strpos($line, 'install-php-extensions');
        if (str_starts_with($line, '#')) {
            continue;
        }
        if ($position === false) {
            continue;
        }

        $tokens = preg_split('/\s+/', substr($line, $position), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        array_shift($tokens);

        foreach ($tokens as $token) {
            $arguments[] = rtrim($token, ';');
        }
    }

    return $arguments;
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
    $arguments = installPhpExtensionsArguments();

    expect($arguments)->not->toBeEmpty();

    // A bare module name is what sends install-php-extensions to pecl.php.net;
    // a source path or archive URL is resolved without it.
    expect($arguments)->not->toContain('redis');
    expect($arguments)->not->toContain('imagick');
});

test('phpredis is built from its git tag with the submodule it needs', function (): void {
    $contents = productionDockerfile();

    // The codeload tarball omits the liblzf submodule, so the source has to come
    // from a clone that carries submodules rather than an archive.
    expect($contents)
        ->toContain('https://github.com/phpredis/phpredis.git')
        ->toContain('--branch "$PHPREDIS_VERSION"')
        ->toContain('--recurse-submodules');

    // The cloned source directory is what install-php-extensions must be handed,
    // in place of the module name it would otherwise resolve through pecl.
    expect(installPhpExtensionsArguments())->toContain('/tmp/phpredis');
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

    // One rolling issue, not a fresh one every month: the open one is found
    // first, commented on while the pins stay behind, and closed once they are
    // current again.
    expect($run)
        ->toContain('gh issue list')
        ->toContain('gh issue comment')
        ->toContain('gh issue close');
});
