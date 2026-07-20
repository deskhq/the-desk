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

test('a base branch that exists locally is forked from the local ref', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'checkout', '--quiet', '-b', 'develop', 'origin/develop');
    file_put_contents($clone.'/README.md', "local develop only\n");
    runGit($clone, 'commit', '--quiet', '-am', 'local develop only');
    runGit($clone, 'checkout', '--quiet', 'master');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0)
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'develop'))
        ->and(gitRevision($root.'/wt', 'HEAD'))->not->toBe(gitRevision($clone, 'origin/develop'));
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
