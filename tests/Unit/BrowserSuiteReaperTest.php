<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

/**
 * Every browser run used to leave its Playwright server behind (#955).
 *
 * `Pest\Plugins\Parallel::handleArguments()` ends the parallel parent with
 * `exit()` the moment paratest returns, so the browser plugin's `terminate()` —
 * the only thing that calls `playwright()->stop()` — never runs in the very
 * process that started the server. One orphan per run, and a killed run adds its
 * paratest workers on top. They then compete for the cores the suite is acutely
 * sensitive to (#948), so a machine that has run a few sweeps fails unrelated
 * tests with the geometry flakes of #786 and reads as a product regression.
 *
 * The runner therefore reaps: strays from earlier runs on the way in, whatever
 * this run leaves on the way out — however it ends. These tests pin what counts
 * as a stray, since the two ways of getting that wrong are both bad: too narrow
 * and the leak survives, too broad and a run kills a sibling's processes.
 */
function runBrowserReaperLib(string $snippet, ?callable $configure = null): Process
{
    $script = dirname(__DIR__, 2).'/bin/browser-tests';

    $process = new Process(['bash', '-c', sprintf(
        'BROWSER_TESTS_LIB=1 . %s; %s',
        escapeshellarg($script),
        $snippet,
    )]);

    if ($configure instanceof Closure) {
        $configure($process);
    } else {
        $process->run();
    }

    return $process;
}

/**
 * A command line that no other process on the machine can match, so the tests
 * below never mistake a real stray for the one they started.
 */
function fakeStrayCommand(string $shape): string
{
    $marker = 'desk-955-'.bin2hex(random_bytes(6));

    return match ($shape) {
        'playwright' => 'node ./node_modules/.bin/playwright run-server --host 127.0.0.1 --port 1 --mode launchServer '.$marker,
        'worker' => '/usr/bin/php8.5 /var/www/html/vendor/pestphp/pest/bin/worker.php --test-directory=tests --status-file /tmp/'.$marker,
        default => 'some-unrelated-daemon --forever '.$marker,
    };
}

/**
 * The shell that renames a `sleep` into the given command line, so a stray can
 * be faked without running a Playwright server or a paratest worker for real.
 */
function fakeStrayShell(string $command): string
{
    return sprintf('exec -a %s sleep 300', escapeshellarg($command));
}

/**
 * Starts a fake stray owned by nothing: the shell that forks it exits at once,
 * which reparents it onto init exactly as a run that ended without stopping it
 * does. Returns its pid once the process table shows it.
 */
function startOrphanedStray(string $command, ?string $workingDirectory = null): int
{
    (new Process(['bash', '-c', fakeStrayShell($command).' &'], $workingDirectory))->mustRun();

    return waitForStrayPid($command);
}

/**
 * Starts an orphaned fake stray that wraps a second one, the way the Playwright
 * server runs behind the shell that launched it. Killing the wrapper is what
 * reparents the inner process onto init, so a reaper that sweeps once leaves it
 * running.
 */
function startOrphanedStrayChain(string $wrapper, string $wrapped): void
{
    (new Process(['bash', '-c', sprintf(
        '( exec -a %s bash -c %s ) &',
        escapeshellarg($wrapper),
        escapeshellarg(fakeStrayShell($wrapped).' & wait'),
    )]))->mustRun();

    waitForStrayPid($wrapped);
}

/**
 * The pid of the process whose command line contains the given text, waiting
 * for it to appear (a forked process is not in the table the instant its parent
 * returns).
 */
function waitForStrayPid(string $command): int
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $pid = findStrayPid($command);

        if ($pid !== null) {
            return $pid;
        }

        Sleep::usleep(50_000);
    }

    throw new RuntimeException('The fake stray never appeared in the process table.');
}

/**
 * The pid of the `sleep` renamed to the given command line, or null when no
 * such process is running.
 */
function findStrayPid(string $command): ?int
{
    $process = new Process(['ps', '-eo', 'pid=,ppid=,args=']);
    $process->run();

    foreach (explode("\n", $process->getOutput()) as $line) {
        $fields = preg_split('/\s+/', trim($line), 3) ?: [];

        if (count($fields) < 3) {
            continue;
        }

        // The renamed sleep carries the command line *and* its own argument, so
        // neither the shell about to exec into it nor the one wrapping it match.
        if (str_contains($fields[2], $command) && str_ends_with($fields[2], ' 300')) {
            return (int) $fields[0];
        }
    }

    return null;
}

/**
 * Whether the fake stray is gone, waited for: a reaper that signals a process
 * does not get to decide when the kernel removes it.
 */
function strayIsGone(string $command): bool
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (findStrayPid($command) === null) {
            return true;
        }

        Sleep::usleep(50_000);
    }

    return false;
}

/**
 * Kills whatever the test started, matched on the marker rather than on the pid
 * it was given: a wrapper carries its wrapped process's command line too, and
 * both have to go however the assertions turned out.
 */
function killStray(string $command): void
{
    (new Process(['pkill', '-9', '-f', $command]))->run();
}

test('a Playwright server orphaned by an earlier run is reported as a stray', function (): void {
    $command = fakeStrayCommand('playwright');
    $pid = startOrphanedStray($command);

    try {
        expect(runBrowserReaperLib('stray_processes')->getOutput())->toContain((string) $pid);
    } finally {
        killStray($command);
    }
});

/*
 * The obvious cleanup — `pkill -f "pest tests/Browser"` — misses these entirely:
 * a paratest worker runs as `pest/bin/worker.php --test-directory=tests`, which
 * names neither pest's invocation nor the browser suite.
 */
test('a paratest worker orphaned by a killed run is reported as a stray too', function (): void {
    $command = fakeStrayCommand('worker');
    $pid = startOrphanedStray($command);

    try {
        expect($command)->not->toContain('pest tests/Browser')
            ->and(runBrowserReaperLib('stray_processes')->getOutput())->toContain((string) $pid);
    } finally {
        killStray($command);
    }
});

/*
 * A run in progress keeps its parent, so the suite next door — or the PHP gate,
 * whose paratest workers are indistinguishable from this suite's — must survive
 * a reap.
 */
test('a process still owned by a live parent is left alone', function (): void {
    $command = fakeStrayCommand('playwright');

    $owner = new Process(['bash', '-c', fakeStrayShell($command)]);
    $owner->start();

    try {
        waitForStrayPid($command);

        expect(runBrowserReaperLib('reap_strays "under test"')->getOutput())->not->toContain($command)
            ->and(findStrayPid($command))->not->toBeNull();
    } finally {
        $owner->stop(signal: SIGKILL);
        killStray($command);
    }
});

test('an orphan that has nothing to do with the suite is left alone', function (): void {
    $command = fakeStrayCommand('unrelated');
    $pid = startOrphanedStray($command);

    try {
        expect(runBrowserReaperLib('stray_processes')->getOutput())->not->toContain((string) $pid);
    } finally {
        killStray($command);
    }
});

/*
 * Worktrees share a machine, so a run here reaping a sibling checkout's live
 * server would trade one mystery red sweep for another.
 */
test('a stray belonging to another checkout is left alone', function (): void {
    $command = fakeStrayCommand('playwright');
    $pid = startOrphanedStray($command, sys_get_temp_dir());

    try {
        expect(runBrowserReaperLib('stray_processes')->getOutput())->not->toContain((string) $pid);
    } finally {
        killStray($command);
    }
})->skip(! is_dir('/proc'), 'Ownership is read from /proc, which this platform does not have.');

test('the reaper kills the strays and names what it reaped', function (): void {
    $command = fakeStrayCommand('playwright');
    $pid = startOrphanedStray($command);

    try {
        $output = runBrowserReaperLib('reap_strays "left behind by an earlier run"')->getErrorOutput();

        expect($output)->toContain((string) $pid)
            ->and($output)->toContain('left behind by an earlier run')
            ->and(strayIsGone($command))->toBeTrue();
    } finally {
        killStray($command);
    }
});

test('a stray only orphaned by reaping its wrapper is reaped as well', function (): void {
    $wrapper = fakeStrayCommand('playwright');
    $wrapped = fakeStrayCommand('playwright');

    startOrphanedStrayChain($wrapper, $wrapped);

    try {
        runBrowserReaperLib('reap_strays "under test"');

        expect(strayIsGone($wrapped))->toBeTrue();
    } finally {
        killStray($wrapped);
    }
});

test('the runner reaps strays left by an earlier run before it starts', function (): void {
    $command = fakeStrayCommand('playwright');
    startOrphanedStray($command);

    try {
        expect(runBrowserReaperLib('run_reaped true')->getErrorOutput())->toContain('earlier run')
            ->and(strayIsGone($command))->toBeTrue();
    } finally {
        killStray($command);
    }
});

test('the runner reaps what the run itself leaks, and still reports its exit code', function (): void {
    $command = fakeStrayCommand('playwright');

    $process = runBrowserReaperLib(sprintf(
        'run_reaped bash -c %s',
        escapeshellarg(fakeStrayShell($command).' & exit 3'),
    ));

    try {
        expect($process->getExitCode())->toBe(3)
            ->and($process->getErrorOutput())->toContain('this run')
            ->and(strayIsGone($command))->toBeTrue();
    } finally {
        killStray($command);
    }
});

test('an interrupted run reaps on its way out as well', function (): void {
    $command = fakeStrayCommand('playwright');

    $process = runBrowserReaperLib(
        sprintf('run_reaped bash -c %s', escapeshellarg(fakeStrayShell($command).' & sleep 60')),
        static fn (Process $process): mixed => $process->start(),
    );

    try {
        waitForStrayPid($command);

        $process->signal(SIGTERM);
        $process->wait();

        expect(strayIsGone($command))->toBeTrue();
    } finally {
        $process->stop(signal: SIGKILL);
        killStray($command);
    }
});

/*
 * An exec'd pest replaces this shell, and a replaced shell has no trap left to
 * fire — which is how the leak survived every normally-completed run.
 */
test('the runner drives pest through the reaper instead of exec-ing it', function (): void {
    $runner = (string) file_get_contents(dirname(__DIR__, 2).'/bin/browser-tests');

    expect($runner)->toContain('run_reaped "${PEST_COMMAND[@]}"')
        ->and($runner)->not->toContain('exec "${PEST_COMMAND[@]}"');
});
