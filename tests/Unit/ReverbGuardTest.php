<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\ReverbGuard;

/**
 * The browser suite subscribes real Echo clients to a live Reverb server, so a
 * stopped one fails every realtime test with a message that names the product
 * rather than the missing WebSocket server ("Expected to see text [Live hello
 * from Alice]"). Running the PHP gate is enough to leave the container stopped,
 * and nothing reports that (issue #954). These tests pin the preflight that
 * turns those failures into one message naming the server.
 */

/**
 * The fixture prefix, scoped to this process, for the same reason
 * StaleBundleGuardTest scopes its own: every paratest worker shares one temp
 * directory, and a sweep that matched them all would delete a rival worker's
 * fixtures out from under it.
 */
function reverbFixturePrefix(): string
{
    return 'reverb-guard-'.getmypid().'-';
}

/**
 * Build a throwaway checkout whose .env holds the given lines.
 *
 * @param  list<string>  $lines
 */
function reverbFixture(array $lines = []): string
{
    $base = sys_get_temp_dir().'/'.reverbFixturePrefix().bin2hex(random_bytes(8));

    mkdir($base, recursive: true);
    file_put_contents($base.'/.env', implode(PHP_EOL, $lines).PHP_EOL);

    return $base;
}

/**
 * A TCP server on a free loopback port, and that port. The socket is never
 * accepted from: a connection completing into the listen backlog is all the
 * guard checks for.
 *
 * @return array{0: resource, 1: int}
 */
function listeningPort(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    expect($server)->not->toBeFalse($error);

    /** @var resource $server */
    $name = (string) stream_socket_get_name($server, false);

    return [$server, (int) substr($name, (int) strrpos($name, ':') + 1)];
}

/**
 * A loopback port nothing can be listening on.
 *
 * Fixed rather than allocated and released, which is the obvious way to find a
 * free port and is racy under paratest: between the release and the probe,
 * another worker's `listeningPort()` can be handed the very same ephemeral port
 * and start listening on it, and a test that needs no server then finds one.
 * Port 1 is privileged, so no worker can bind it, and the connection is refused
 * outright rather than left to time out.
 */
function refusedPort(): int
{
    return 1;
}

/**
 * The environment a guard subprocess runs under: the endpoint it should resolve,
 * with both keys *removed* from the inherited environment when it names none.
 *
 * Removed rather than merely left unset, because the rest of the suite boots the
 * application, and Laravel's Dotenv putenv()s this checkout's own REVERB_HOST as
 * it goes. A child would inherit that, and a test about the .env fallback would
 * then resolve the developer's live server instead of its fixture.
 *
 * @return array<string, string|false>
 */
function reverbEnvironment(?string $host = null, ?int $port = null): array
{
    return [
        'REVERB_HOST' => $host ?? false,
        'REVERB_PORT' => $port !== null ? (string) $port : false,
    ];
}

/**
 * Drive `ensureReverbIsRunning()` in its own process, so the run it aborts is
 * not the one running this test.
 *
 * @param  list<string>  $basePaths  one call per base path, in order
 */
function runReverbGuard(array $basePaths, ?string $host = null, ?int $port = null): Process
{
    $calls = implode('', array_map(
        static fn (string $basePath): string => sprintf(
            'Tests\Support\ReverbGuard::ensureReverbIsRunning(%s);',
            var_export($basePath, true),
        ),
        $basePaths,
    ));

    $process = new Process([PHP_BINARY, '-r', sprintf(
        'require %s; %s echo "the suite ran";',
        var_export(dirname(__DIR__).'/Support/ReverbGuard.php', true),
        $calls,
    )], env: reverbEnvironment($host, $port));
    $process->run();

    return $process;
}

/**
 * The endpoint the guard resolves, as `host:port`, read in its own process so
 * an ambient REVERB_HOST cannot leak in from a booted application.
 */
function resolvedEndpoint(string $basePath, ?string $host = null, ?int $port = null): string
{
    $process = new Process([PHP_BINARY, '-r', sprintf(
        'require %s; printf("%%s:%%d", ...Tests\Support\ReverbGuard::endpoint(%s));',
        var_export(dirname(__DIR__).'/Support/ReverbGuard.php', true),
        var_export($basePath, true),
    )], env: reverbEnvironment($host, $port));
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return $process->getOutput();
}

/**
 * Call the runner's Reverb check without running the suite it guards.
 */
function runRunnerReverbCheck(string $basePath, ?string $host = null, ?int $port = null): Process
{
    $environment = reverbEnvironment($host, $port);

    $exported = implode('', array_map(
        static fn (string|false $value, string $key): string => $value === false
            ? 'unset '.$key.'; '
            : 'export '.$key.'='.escapeshellarg($value).'; ',
        $environment,
        array_keys($environment),
    ));

    $process = new Process(['bash', '-c', sprintf(
        'BROWSER_TESTS_LIB=1 . %s; %sassert_reverb_is_running %s',
        escapeshellarg(dirname(__DIR__, 2).'/bin/browser-tests'),
        $exported,
        escapeshellarg($basePath),
    )]);
    $process->run();

    return $process;
}

afterEach(function (): void {
    $filesystem = new Filesystem;

    foreach ($filesystem->glob(sys_get_temp_dir().'/'.reverbFixturePrefix().'*') ?: [] as $fixture) {
        $filesystem->deleteDirectory($fixture);
    }
});

it('stays quiet when Reverb is accepting connections', function (): void {
    [$server, $port] = listeningPort();

    expect(ReverbGuard::unreachableWarning('127.0.0.1', $port))->toBeNull();

    fclose($server);
});

it('names the endpoint and the command that starts it when nothing is listening', function (): void {
    $port = refusedPort();

    expect(ReverbGuard::unreachableWarning('127.0.0.1', $port))
        ->toContain('not accepting connections')
        ->toContain('127.0.0.1:'.$port)
        ->toContain('./vendor/bin/sail up -d reverb');
});

it('says which server is missing rather than which test failed', function (): void {
    expect(ReverbGuard::unreachableWarning('127.0.0.1', refusedPort()))
        ->toContain('Reverb')
        ->toContain('realtime');
});

// A refused connection is this guard's whole subject, so it must not also be a
// diagnostic: PHPUnit reports `@`-suppressed warnings, which would tag every
// run of the suite that starts without Reverb with a warning of its own.
it('probes a dead endpoint without raising a PHP warning', function (): void {
    $raised = [];

    set_error_handler(static function (int $type, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        ReverbGuard::unreachableWarning('127.0.0.1', refusedPort());
    } finally {
        restore_error_handler();
    }

    expect($raised)->toBeEmpty();
});

it('reads the endpoint from the process environment', function (): void {
    expect(resolvedEndpoint(
        reverbFixture(['REVERB_HOST=ignored', 'REVERB_PORT=9999']),
        host: '10.0.0.1',
        port: 9001,
    ))->toBe('10.0.0.1:9001');
});

it('falls back to the checkout .env when the environment names no server', function (): void {
    expect(resolvedEndpoint(reverbFixture(['APP_ENV=local', 'REVERB_HOST="reverb"', 'REVERB_PORT=8080'])))
        ->toBe('reverb:8080');
});

it('falls back to the defaults when neither the environment nor .env names one', function (): void {
    expect(resolvedEndpoint(reverbFixture(['APP_ENV=local'])))->toBe('127.0.0.1:8080');
});

it('ignores a commented-out or differently-suffixed .env key', function (): void {
    expect(resolvedEndpoint(reverbFixture([
        '# REVERB_HOST=commented',
        'REVERB_HOST_PUBLIC=ws.example.test',
        'REVERB_PORT_PUBLIC=443',
    ])))->toBe('127.0.0.1:8080');
});

it('treats an empty .env value as unset', function (): void {
    expect(resolvedEndpoint(reverbFixture(['REVERB_HOST=', 'REVERB_PORT='])))->toBe('127.0.0.1:8080');
});

it('resolves the defaults when the checkout has no .env at all', function (): void {
    expect(resolvedEndpoint(sys_get_temp_dir().'/'.reverbFixturePrefix().'absent'))->toBe('127.0.0.1:8080');
});

it('aborts the run before a single browser test executes', function (): void {
    $process = runReverbGuard([reverbFixture()], host: '127.0.0.1', port: refusedPort());

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->not->toContain('the suite ran')
        ->and($process->getErrorOutput())
        ->toContain('not accepting connections')
        ->toContain('./vendor/bin/sail up -d reverb');
});

it('lets the run proceed when Reverb is up', function (): void {
    [$server, $port] = listeningPort();

    $process = runReverbGuard([reverbFixture()], host: '127.0.0.1', port: $port);

    fclose($server);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('the suite ran')
        ->and($process->getErrorOutput())->toBe('');
});

// One TCP connect per test would pay a network round trip 272 times over, and
// worse, would abort mid-suite if the server went down halfway. The memo makes
// it a preflight: a second call against an unmistakably dead endpoint must not
// even look.
it('checks once per process rather than once per test', function (): void {
    [$server, $port] = listeningPort();

    // Each call reads its own checkout, so the endpoints have to live in the
    // fixtures rather than in the environment the two share.
    $live = reverbFixture(['REVERB_HOST=127.0.0.1', 'REVERB_PORT='.$port]);
    $dead = reverbFixture(['REVERB_HOST=127.0.0.1', 'REVERB_PORT='.refusedPort()]);

    $process = runReverbGuard([$live, $dead]);

    fclose($server);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('the suite ran');
});

// Wired into the `->in('Browser')` chain rather than only into bin/browser-tests,
// because the documented `sail bin pest tests/Browser` entry point never runs
// that script.
it('runs from the browser suite bootstrap, whichever way the suite is started', function (): void {
    $bootstrap = (string) file_get_contents(dirname(__DIR__).'/Pest.php');

    // Narrowed to the chain itself, so a guard call anywhere else in the
    // bootstrap cannot stand in for one the browser suite actually runs.
    $browserChain = (string) strstr((string) strstr($bootstrap, "->group('browser')"), "->in('Browser');", before_needle: true);

    expect($browserChain)->toContain('ReverbGuard::ensureReverbIsRunning(base_path())');
});

// Also checked ahead of paratest, not only inside it: aborting a worker surfaces
// the message wrapped in a WorkerCrashedException and followed by paratest's own
// usage dump, whereas failing before the fork prints the message and nothing
// else. `composer test:browser` and CI both come through here.
it('stops the runner before it forks any worker', function (): void {
    $process = runRunnerReverbCheck(reverbFixture(), host: '127.0.0.1', port: refusedPort());

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())
        ->toContain('not accepting connections')
        ->toContain('./vendor/bin/sail up -d reverb');
});

it('lets the runner through when Reverb is up', function (): void {
    [$server, $port] = listeningPort();

    $process = runRunnerReverbCheck(reverbFixture(), host: '127.0.0.1', port: $port);

    fclose($server);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('');
});
