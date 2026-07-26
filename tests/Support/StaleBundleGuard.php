<?php

declare(strict_types=1);

namespace Tests\Support;

final class StaleBundleGuard
{
    /**
     * Whether this process has already swept the sources.
     */
    private static bool $swept = false;

    /**
     * The Vite manifest, relative to the project root. Its mtime is the moment
     * the current bundle was written.
     */
    public const string MANIFEST = 'public/build/manifest.json';

    /**
     * The source trees Vite bundles, relative to the project root.
     */
    public const array WATCHED_DIRECTORIES = [
        'resources/js',
        'resources/css',
        'resources/pwa',
    ];

    /**
     * Individual files outside the source trees that change what Vite emits,
     * relative to the project root.
     */
    public const array WATCHED_FILES = [
        'vite.config.ts',
        'package.json',
        'package-lock.json',
    ];

    /**
     * Trees under a watched directory that the guard must not stat, relative to
     * the project root.
     *
     * All four are git-ignored generator output. `actions`, `routes` and
     * `wayfinder` are rewritten by the Vite build itself, and `generated` holds
     * the ambient `App.*` types `artisan typescript:transform` writes — types
     * only, erased before anything reaches the bundle. Watching them would trip
     * the guard for changes that cannot make the bundle stale.
     */
    public const array IGNORED_DIRECTORIES = [
        'resources/js/actions',
        'resources/js/routes',
        'resources/js/wayfinder',
        'resources/js/generated',
    ];

    /**
     * The newest bundled source's mtime and path, relative to the project root.
     *
     * @return array{0: int, 1: string}
     */
    private static function newestSource(string $basePath): array
    {
        $newestMtime = 0;
        $newestPath = '';

        $sources = self::WATCHED_FILES;

        foreach (self::WATCHED_DIRECTORIES as $directory) {
            $sources = [...$sources, ...self::filesIn($basePath, $directory)];
        }

        foreach ($sources as $relative) {
            $mtime = (int) @filemtime($basePath.'/'.$relative);

            if ($mtime > $newestMtime) {
                $newestMtime = $mtime;
                $newestPath = $relative;
            }
        }

        return [$newestMtime, $newestPath];
    }

    /**
     * Every file under the given directory, recursively, as paths relative to
     * the project root — minus the trees the guard ignores.
     *
     * @return list<string>
     */
    private static function filesIn(string $basePath, string $directory): array
    {
        if (in_array($directory, self::IGNORED_DIRECTORIES, strict: true) || ! is_dir($basePath.'/'.$directory)) {
            return [];
        }

        $files = [];

        foreach (array_diff(scandir($basePath.'/'.$directory) ?: [], ['.', '..']) as $entry) {
            $relative = $directory.'/'.$entry;

            if (is_dir($basePath.'/'.$relative)) {
                $files = [...$files, ...self::filesIn($basePath, $relative)];

                continue;
            }

            $files[] = $relative;
        }

        return $files;
    }

    /**
     * Abort the run, once, if the browser suite is about to drive a bundle
     * older than the sources it was built from.
     *
     * Reported by writing the message and exiting rather than by throwing: a
     * thrown exception would surface as one failure per test, which is the very
     * shape of output this guard exists to replace.
     */
    public static function ensureFreshBundle(string $basePath): void
    {
        if (self::$swept) {
            return;
        }

        self::$swept = true;

        $warning = self::staleBundleWarning($basePath);

        if ($warning === null) {
            return;
        }

        fwrite(STDERR, PHP_EOL.$warning.PHP_EOL.PHP_EOL);

        exit(1);
    }

    /**
     * The message to show when the compiled bundle is older than its sources,
     * or null when the bundle is current.
     */
    public static function staleBundleWarning(string $basePath): ?string
    {
        [$newestMtime, $newestPath] = self::newestSource($basePath);

        $manifestMtime = (int) @filemtime($basePath.'/'.self::MANIFEST);

        if ($newestMtime <= $manifestMtime) {
            return null;
        }

        return <<<MESSAGE
              Your compiled assets are older than your sources.

              The browser suite serves public/build, so these tests would run against a
              stale bundle and fail as if the app were broken.

                  ./vendor/bin/sail npm run build

              (newest source: {$newestPath})
            MESSAGE;
    }
}
