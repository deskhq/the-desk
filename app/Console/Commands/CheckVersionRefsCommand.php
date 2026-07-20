<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Assert that hardcoded release-version strings cannot silently rot.
 *
 * release-please only rewrites a version on a line carrying an
 * `x-release-please-*` marker inside a file registered under `extra-files`.
 * Both halves drift invisibly: a registered file whose marker was removed stops
 * being stamped without warning, and an operator-facing line naming a version
 * that was never registered ships stale install instructions to self-hosters.
 * This command fails the build on either, so the `release-version-refs` skill's
 * instruction is backed by an invariant.
 */
#[Signature('release:check-version-refs {--root= : Repository root to check; defaults to the project root}')]
#[Description('Check that every release-please extra-file is annotated and no operator-facing file names an unstamped version')]
class CheckVersionRefsCommand extends Command
{
    /**
     * Operator-facing paths scanned for hardcoded versions, relative to the
     * repository root. Directories are walked recursively.
     *
     * @var list<string>
     */
    private const array SCANNED_PATHS = [
        'README.md',
        '.env.example',
        '.env.prod.example',
        'docker',
        'docs/src/content/docs',
    ];

    /**
     * Lines that name a version on purpose and must never be stamped. A line is
     * exempt when its file matches `path` and its text contains `contains`;
     * every entry is a deliberate decision, so keep the reason with it.
     *
     * @var list<array{path: string, contains: string, reason: string}>
     */
    private const array ALLOWLIST = [
        [
            'path' => 'docker/install.sh',
            'contains' => '--version must be a release like',
            'reason' => 'Illustrative version in a validation error, not a version we ship.',
        ],
        [
            'path' => 'docker/upgrade.sh',
            'contains' => 'is not a release like',
            'reason' => 'Illustrative version in a validation error, not a version we ship.',
        ],
        [
            'path' => 'docker/upgrade.sh',
            'contains' => 'inline comment is stripped',
            'reason' => 'Comment describing the shape of the .env template line, not an instruction.',
        ],
    ];

    /**
     * A semver-shaped version, optionally `v`-prefixed. The guards on either
     * side keep dotted quads such as IP addresses out while still matching a
     * version that ends a sentence ("upgrade to v1.2.3.").
     */
    private const string VERSION_PATTERN = '/(?<![\w.])v?\d+\.\d+\.\d+(?!\.?\d)/';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $root = rtrim((string) ($this->option('root') ?? '') ?: base_path(), '/');
        $configPath = $root.'/release-please-config.json';

        if (! File::exists($configPath)) {
            $this->error('release-please-config.json not found at '.$configPath);

            return self::FAILURE;
        }

        $registered = $this->registeredPaths($configPath);

        $failures = [
            ...$this->annotationFailures($root, $registered),
            ...$this->unstampedVersionFailures($root, $registered),
        ];

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        if ($failures !== []) {
            return self::FAILURE;
        }

        $this->info('Version references are all stamped by release-please.');

        return self::SUCCESS;
    }

    /**
     * The `extra-files` paths registered across every configured package.
     *
     * @return list<string>
     */
    private function registeredPaths(string $configPath): array
    {
        $config = json_decode((string) File::get($configPath), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array{path?: string}|string> $entries */
        $entries = data_get($config, 'packages.*.extra-files.*', []);

        return array_map(
            static fn (array|string $entry): string => is_array($entry) ? (string) ($entry['path'] ?? '') : $entry,
            $entries,
        );
    }

    /**
     * Registered files that release-please would open and find nothing to stamp.
     *
     * @param  list<string>  $registered
     * @return list<string>
     */
    private function annotationFailures(string $root, array $registered): array
    {
        $failures = [];

        foreach ($registered as $path) {
            if (! File::exists($root.'/'.$path)) {
                $failures[] = $path.' is registered under extra-files but does not exist.';

                continue;
            }

            if (! str_contains((string) File::get($root.'/'.$path), 'x-release-please')) {
                $failures[] = $path.' is registered under extra-files but has no x-release-please marker, so release-please stamps nothing into it.';
            }
        }

        return $failures;
    }

    /**
     * Operator-facing lines naming a version that release-please will not stamp.
     *
     * @param  list<string>  $registered
     * @return list<string>
     */
    private function unstampedVersionFailures(string $root, array $registered): array
    {
        $failures = [];

        foreach ($this->scannedFiles($root) as $path) {
            $isRegistered = in_array($path, $registered, true);
            $inBlock = false;

            foreach (File::lines($root.'/'.$path) as $index => $rawLine) {
                $line = (string) $rawLine;

                if (str_contains($line, 'x-release-please-start-')) {
                    $inBlock = true;
                }

                if (str_contains($line, 'x-release-please-end')) {
                    $inBlock = false;
                }

                $failure = $this->lineFailure($path, $line, $index + 1, $isRegistered, $inBlock);

                if ($failure !== null) {
                    $failures[] = $failure;
                }
            }
        }

        return $failures;
    }

    /**
     * Why this line's version reference will not be stamped, or null when it is
     * either stamped, deliberately exempt, or names no version at all.
     */
    private function lineFailure(string $path, string $line, int $number, bool $isRegistered, bool $inBlock): ?string
    {
        if (preg_match(self::VERSION_PATTERN, $line) !== 1 || $this->isAllowlisted($path, $line)) {
            return null;
        }

        $isAnnotated = $inBlock || str_contains($line, 'x-release-please-version')
            || str_contains($line, 'x-release-please-major')
            || str_contains($line, 'x-release-please-minor');

        if ($isAnnotated && $isRegistered) {
            return null;
        }

        return $isAnnotated
            ? $path.':'.$number.' carries a release-please annotation but the file is not registered under extra-files, so it is never stamped.'
            : $path.':'.$number.' names a version on a line release-please will not stamp — annotate it and register the file, or add a commented allowlist entry.';
    }

    private function isAllowlisted(string $path, string $line): bool
    {
        foreach (self::ALLOWLIST as $entry) {
            if ($entry['path'] === $path && str_contains($line, $entry['contains'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every scanned file, as a path relative to the repository root.
     *
     * @return list<string>
     */
    private function scannedFiles(string $root): array
    {
        $paths = [];

        foreach (self::SCANNED_PATHS as $scanned) {
            $absolute = $root.'/'.$scanned;

            if (File::isDirectory($absolute)) {
                foreach (File::allFiles($absolute) as $file) {
                    $paths[] = $scanned.'/'.str_replace('\\', '/', $file->getRelativePathname());
                }

                continue;
            }

            if (File::isFile($absolute)) {
                $paths[] = $scanned;
            }
        }

        return $paths;
    }
}
