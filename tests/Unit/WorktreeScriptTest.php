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
 * `worktree create <NNN> develop` land on develop itself (issue #619).
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
    runGit($root.'/upstream', 'branch', 'develop');

    runGit($root, 'clone', '--quiet', $root.'/upstream', 'main');

    return [$root.'/main', $root];
}

test('a base branch that exists only on the remote still forks the issue branch', function (): void {
    [$clone, $root] = worktreeFixtureClone();

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(runGit($clone, 'branch', '--list', 'develop')->getOutput())->toBe('');
});

test('a base branch that exists locally is forked from the local ref', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'branch', 'develop', 'origin/develop');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug');
});

test('an existing local branch is attached instead of being re-forked', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'branch', '619-slug', 'origin/develop');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug');
});

test('a base that names the remote-tracking ref outright is honoured', function (): void {
    [$clone, $root] = worktreeFixtureClone();

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug origin/develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug');
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
