<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\StaleBundleGuard;

/**
 * The browser suite serves `public/build`, not `resources/` — the in-process
 * HTTP server hands Playwright the compiled Vite assets. A bundle older than
 * the working tree therefore fails the suite as though the application were
 * broken, and the failures land on whatever shipped most recently, so the
 * natural reading is "the last few PRs regressed" (issue #949). These tests pin
 * the guard that turns that into one message naming the rebuild command.
 */
const FIXTURE_PREFIX = 'stale-bundle-guard-';

/**
 * Build a throwaway checkout whose files carry the given mtimes.
 *
 * @param  array<string, int>  $files  relative path => mtime
 */
function bundleFixture(array $files): string
{
    $base = sys_get_temp_dir().'/'.FIXTURE_PREFIX.bin2hex(random_bytes(8));

    foreach ($files as $relative => $mtime) {
        $path = $base.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }

        file_put_contents($path, '');
        touch($path, $mtime);
    }

    return $base;
}

/**
 * Drive `ensureFreshBundle()` in its own process, so the run it aborts is not
 * the one running this test.
 *
 * @param  list<string>  $basePaths  one call per base path, in order
 */
function runBundleGuard(array $basePaths): Process
{
    $calls = implode('', array_map(
        static fn (string $basePath): string => sprintf(
            'Tests\Support\StaleBundleGuard::ensureFreshBundle(%s);',
            var_export($basePath, true),
        ),
        $basePaths,
    ));

    $process = new Process([PHP_BINARY, '-r', sprintf(
        'require %s; %s echo "the suite ran";',
        var_export(dirname(__DIR__).'/Support/StaleBundleGuard.php', true),
        $calls,
    )]);
    $process->run();

    return $process;
}

afterEach(function (): void {
    $filesystem = new Filesystem;

    foreach ($filesystem->glob(sys_get_temp_dir().'/'.FIXTURE_PREFIX.'*') ?: [] as $fixture) {
        $filesystem->deleteDirectory($fixture);
    }
});

it('stays quiet when the manifest is newer than every bundled source', function (): void {
    $base = bundleFixture([
        'resources/js/app.ts' => 1_700_000_000,
        'resources/css/app.css' => 1_700_000_000,
        'resources/pwa/service-worker.ts' => 1_700_000_000,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    expect(StaleBundleGuard::staleBundleWarning($base))->toBeNull();
});

it('names the rebuild command and the newest source when the bundle is stale', function (): void {
    $base = bundleFixture([
        'resources/js/app.ts' => 1_700_000_000,
        'resources/js/pages/Search.vue' => 1_700_000_200,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    expect(StaleBundleGuard::staleBundleWarning($base))
        ->toContain('older than your sources')
        ->toContain('public/build')
        ->toContain('./vendor/bin/sail npm run build')
        ->toContain('resources/js/pages/Search.vue');
});

it('gives the same message when no manifest has ever been built', function (): void {
    $base = bundleFixture(['resources/js/app.ts' => 1_700_000_000]);

    expect(StaleBundleGuard::staleBundleWarning($base))
        ->toContain('older than your sources')
        ->toContain('./vendor/bin/sail npm run build');
});

it('ignores the git-ignored trees the build writes itself', function (string $generated): void {
    $base = bundleFixture([
        'resources/js/app.ts' => 1_700_000_000,
        $generated => 1_700_000_200,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    expect(StaleBundleGuard::staleBundleWarning($base))->toBeNull();
})->with([
    'resources/js/actions/App/Http/Controllers/SearchController.ts',
    'resources/js/routes/index.ts',
    'resources/js/wayfinder/index.ts',
    'resources/js/generated/generated.d.ts',
]);

it('watches the root files that change what the build emits', function (string $file): void {
    $base = bundleFixture([
        'resources/js/app.ts' => 1_700_000_000,
        $file => 1_700_000_200,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    expect(StaleBundleGuard::staleBundleWarning($base))->toContain($file);
})->with([
    'vite.config.ts',
    'package.json',
    'package-lock.json',
]);

it('leaves Blade out of it, since views are rendered at runtime rather than bundled', function (): void {
    $base = bundleFixture([
        'resources/js/app.ts' => 1_700_000_000,
        'resources/views/app.blade.php' => 1_700_000_200,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    expect(StaleBundleGuard::staleBundleWarning($base))->toBeNull();
});

// The build reads its sources before it writes the manifest, so a source saved
// during the build shares its second on a one-second-resolution stat. Treating
// that tie as fresh keeps the guard from firing on a bundle that is current.
it('treats a source saved in the same second as the manifest as current', function (): void {
    $base = bundleFixture([
        'resources/js/app.ts' => 1_700_000_100,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    expect(StaleBundleGuard::staleBundleWarning($base))->toBeNull();
});

it('aborts the run before a single browser test executes', function (): void {
    $base = bundleFixture([
        'resources/js/pages/Search.vue' => 1_700_000_200,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    $process = runBundleGuard([$base]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->not->toContain('the suite ran')
        ->and($process->getErrorOutput())
        ->toContain('older than your sources')
        ->toContain('./vendor/bin/sail npm run build')
        ->toContain('resources/js/pages/Search.vue');
});

it('lets the run proceed when the bundle is current', function (): void {
    $base = bundleFixture([
        'resources/js/app.ts' => 1_700_000_000,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    $process = runBundleGuard([$base]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('the suite ran')
        ->and($process->getErrorOutput())->toBe('');
});

// The sweep stats every bundled source, so running it per test would pay that
// cost 272 times over. The memo is what makes it once per suite — a second call
// against an unmistakably stale checkout must not even look.
it('sweeps once per process rather than once per test', function (): void {
    $fresh = bundleFixture([
        'resources/js/app.ts' => 1_700_000_000,
        'public/build/manifest.json' => 1_700_000_100,
    ]);
    $stale = bundleFixture(['resources/js/app.ts' => 1_700_000_200]);

    $process = runBundleGuard([$fresh, $stale]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('the suite ran');
});

// Wired into the `->in('Browser')` chain rather than into bin/browser-tests,
// because the documented `sail bin pest tests/Browser` entry point never runs
// that script.
it('runs from the browser suite bootstrap, whichever way the suite is started', function (): void {
    $bootstrap = (string) file_get_contents(dirname(__DIR__).'/Pest.php');
    $browserChain = substr($bootstrap, (int) strpos($bootstrap, "->group('browser')"));

    expect($browserChain)->toContain('StaleBundleGuard::ensureFreshBundle(base_path())');
});

/**
 * Call the runner's bundle check against the given checkout, without running
 * the suite it guards.
 */
function runRunnerBundleCheck(string $basePath): Process
{
    $process = new Process(['bash', '-c', sprintf(
        'BROWSER_TESTS_LIB=1 . %s; assert_fresh_bundle %s',
        escapeshellarg(dirname(__DIR__, 2).'/bin/browser-tests'),
        escapeshellarg($basePath),
    )]);
    $process->run();

    return $process;
}

// Also checked ahead of paratest, not only inside it: aborting a worker
// surfaces the message wrapped in a WorkerCrashedException and followed by
// paratest's own usage dump, whereas failing before the fork prints the message
// and nothing else. `composer test:browser` and CI both come through here.
it('stops the runner before it forks any worker', function (): void {
    $base = bundleFixture([
        'resources/js/pages/Search.vue' => 1_700_000_200,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    $process = runRunnerBundleCheck($base);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())
        ->toContain('older than your sources')
        ->toContain('./vendor/bin/sail npm run build');
});

it('lets the runner through when the bundle is current', function (): void {
    $base = bundleFixture([
        'resources/js/app.ts' => 1_700_000_000,
        'public/build/manifest.json' => 1_700_000_100,
    ]);

    $process = runRunnerBundleCheck($base);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('');
});
