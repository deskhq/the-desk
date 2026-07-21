<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Exercise bin/worktree's git-side helpers directly: sourcing the script with
 * WORKTREE_LIB=1 defines its functions without dispatching a subcommand, so the
 * branch resolution can be driven against throwaway repositories instead of
 * booting Docker.
 */
function runWorktreeLib(string $cwd, string $snippet): Process
{
    $script = dirname(__DIR__, 2).'/bin/worktree';

    $process = new Process(
        ['bash', '-c', 'WORKTREE_LIB=1 . '.escapeshellarg($script).'; '.$snippet],
        $cwd,
    );
    $process->run();

    return $process;
}

function runGit(string $cwd, string ...$arguments): Process
{
    $process = new Process(['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', ...$arguments], $cwd);
    $process->mustRun();

    return $process;
}

/**
 * Build an "upstream" repository carrying master + develop and clone it, so the
 * clone knows develop only as remotes/origin/develop — the state that made
 * `worktree create <NNN> develop` land on develop itself (issue #619). develop
 * carries an extra commit so forking from the wrong base is detectable by SHA,
 * not just by branch name.
 *
 * @return array{0: string, 1: string} the clone path and its parent directory
 */
function worktreeFixtureClone(): array
{
    $root = sys_get_temp_dir().'/worktree-test-'.bin2hex(random_bytes(6));
    mkdir($root.'/upstream', 0o755, true);

    runGit($root.'/upstream', 'init', '--quiet', '--initial-branch=master', '.');
    file_put_contents($root.'/upstream/README.md', "fixture\n");
    runGit($root.'/upstream', 'add', '-A');
    runGit($root.'/upstream', 'commit', '--quiet', '-m', 'init');
    runGit($root.'/upstream', 'checkout', '--quiet', '-b', 'develop');
    file_put_contents($root.'/upstream/README.md', "fixture on develop\n");
    runGit($root.'/upstream', 'commit', '--quiet', '-am', 'develop only');
    runGit($root.'/upstream', 'checkout', '--quiet', 'master');

    runGit($root, 'clone', '--quiet', $root.'/upstream', 'main');

    return [$root.'/main', $root];
}

/**
 * A throwaway directory that is itself a git repository, so it can serve as the
 * cwd for runWorktreeLib: sourcing bin/worktree aborts unless it is invoked from
 * inside a work tree. The repo root is not usable here — inside a worktree's
 * container its .git file points at a host path that does not exist there.
 */
function tempGitDir(string $prefix): string
{
    $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
    mkdir($path, 0o755, true);
    runGit($path, 'init', '--quiet', '--initial-branch=master', '.');

    return $path;
}

/**
 * Stand up a throwaway worktree directory whose ./vendor/bin/sail is a stub that
 * records every invocation, so the Playwright bootstrap can be driven without
 * Docker. The stub answers the "is chromium already there?" probe (`sail shell`)
 * with $probeExit and succeeds for everything else.
 *
 * @return array{0: string, 1: string} the fake worktree path and its call log
 */
function fakeSailWorktree(int $probeExit): array
{
    $path = tempGitDir('worktree-sail');
    mkdir($path.'/vendor/bin', 0o755, true);

    $log = $path.'/sail-calls.log';
    file_put_contents($path.'/vendor/bin/sail', <<<BASH
        #!/usr/bin/env bash
        printf '%s\n' "\$*" >> {$log}
        [ "\$1" = "shell" ] && exit {$probeExit}
        exit 0
        BASH);
    chmod($path.'/vendor/bin/sail', 0o755);

    return [$path, $log];
}

/**
 * The stub's recorded invocations, one per line, so a test can assert on whole
 * commands instead of substrings (the "already installed?" probe shells out to
 * `playwright install --dry-run`, so a substring match cannot tell a probe from
 * a real install).
 *
 * @return list<string>
 */
function sailCalls(string $log): array
{
    if (! is_file($log)) {
        return [];
    }

    return array_values(array_filter(explode("\n", (string) file_get_contents($log)), strlen(...)));
}

function gitRevision(string $cwd, string $revision): string
{
    return trim(runGit($cwd, 'rev-parse', $revision)->getOutput());
}

test('a base branch that exists only on the remote still forks the issue branch', function (): void {
    [$clone, $root] = worktreeFixtureClone();

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'origin/develop'))
        ->and(runGit($clone, 'branch', '--list', 'develop')->getOutput())->toBe('');
});

test('a local base branch behind its remote is fetched and forked from the remote', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'checkout', '--quiet', '-b', 'develop', 'origin/develop');
    runGit($clone, 'checkout', '--quiet', 'master');

    runGit($root.'/upstream', 'checkout', '--quiet', 'develop');
    file_put_contents($root.'/upstream/README.md', "develop moved on\n");
    runGit($root.'/upstream', 'commit', '--quiet', '-am', 'develop moved on');
    runGit($root.'/upstream', 'checkout', '--quiet', 'master');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($root.'/upstream', 'develop'))
        ->and(gitRevision($root.'/wt', 'HEAD'))->not->toBe(gitRevision($clone, 'develop'));
});

test('a base branch that exists only locally is forked from that local branch', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'checkout', '--quiet', '-b', 'epic', 'origin/develop');
    file_put_contents($clone.'/README.md', "epic only\n");
    runGit($clone, 'commit', '--quiet', '-am', 'epic only');
    runGit($clone, 'checkout', '--quiet', 'master');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug epic');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'epic'));
});

test('HEAD as a base forks from the local checkout, not origin/HEAD', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    file_put_contents($clone.'/README.md', "local only\n");
    runGit($clone, 'commit', '--quiet', '-am', 'local only');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug HEAD');

    expect($process->getExitCode())->toBe(0)
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'master'))
        ->and(gitRevision($root.'/wt', 'HEAD'))->not->toBe(gitRevision($clone, 'origin/master'));
});

test('an existing local branch is attached instead of being re-forked', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'branch', '619-slug', 'master');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'master'))
        ->and(gitRevision($root.'/wt', 'HEAD'))->not->toBe(gitRevision($clone, 'origin/develop'));
});

test('a base that names the remote-tracking ref outright is honoured', function (): void {
    [$clone, $root] = worktreeFixtureClone();

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug origin/develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'origin/develop'));
});

test('a base carried by several remotes is rejected as ambiguous', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'remote', 'add', 'mirror', $root.'/upstream');
    runGit($clone, 'fetch', '--quiet', 'mirror');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('ambiguous')
        ->and(is_dir($root.'/wt'))->toBeFalse();
});

test('an unknown base fails loudly instead of guessing', function (): void {
    [$clone, $root] = worktreeFixtureClone();

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug nope');

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('nope')
        ->and(is_dir($root.'/wt'))->toBeFalse();
});

test('a worktree sitting on the wrong branch aborts the bootstrap', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'worktree', 'add', '--quiet', '-b', 'other', $root.'/wt', 'origin/develop');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('619-slug')
        ->and($process->getErrorOutput())->toContain('other');
});

test('a fresh worktree installs the Playwright system deps as root and the chromium browser', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 1);

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0)
        ->and(sailCalls($log))->toContain('root-shell -c npx playwright install-deps chromium')
        ->and(sailCalls($log))->toContain('npx playwright install chromium');
});

test('a worktree that already has chromium skips the Playwright install', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 0);

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0)
        ->and(sailCalls($log))->not->toContain('root-shell -c npx playwright install-deps chromium')
        ->and(sailCalls($log))->not->toContain('npx playwright install chromium');
});

test('the generated override rebinds Reverb to the worktree host port and keeps the container on 8080', function (): void {
    $path = tempGitDir('worktree-override');

    $process = runWorktreeLib($path, 'write_override '.escapeshellarg($path).' 579 20002');
    $override = (string) file_get_contents($path.'/compose.override.yaml');

    expect($process->getExitCode())->toBe(0)
        ->and($override)->toContain("'20002:8080'")
        ->and($override)->toContain('ports: !override');
});

test('the generated env pins the container-internal Reverb port while offsetting the host ports', function (): void {
    $path = tempGitDir('worktree-env');
    file_put_contents($path.'/source.env', "APP_NAME=Desk\nAPP_PORT=80\nREVERB_PORT=443\nDEMO_MODE=true\n");

    $process = runWorktreeLib(
        $path,
        'write_env '.escapeshellarg($path).' '.escapeshellarg($path.'/source.env').' 20000 20001 20003 20004 desk-579',
    );
    $env = (string) file_get_contents($path.'/.env');

    expect($process->getExitCode())->toBe(0)
        ->and($env)->toContain("\nREVERB_PORT=8080\n")
        ->and($env)->toContain("\nAPP_PORT=20000\n")
        ->and($env)->toContain("\nVITE_PORT=20001\n")
        ->and($env)->toContain("\nFORWARD_DB_PORT=20003\n")
        ->and($env)->toContain("\nFORWARD_REDIS_PORT=20004\n")
        ->and($env)->toContain("\nCOMPOSE_PROJECT_NAME=desk-579\n")
        ->and($env)->toContain("\nAPP_URL=http://localhost:20000\n")
        ->and($env)->toContain("\nDEMO_MODE=false\n");
});
