<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Exercise bin/worktree's git-side helpers directly: sourcing the script with
 * WORKTREE_LIB=1 defines its functions without dispatching a subcommand, so the
 * branch resolution can be driven against throwaway repositories instead of
 * booting Docker.
 */
function runWorktreeLib(string $cwd, string $snippet): Process
{
    $script = dirname(__DIR__, 2).'/bin/worktree';

    // stdin is pointed at /dev/null up front so the stubs' drain (see jqStub)
    // reaches EOF instantly on the calls that hand jq a file rather than a pipe.
    //
    // HOME is pinned inside the fixture rather than left to each call site:
    // everything bin/worktree shares between runs — the registry, the global
    // lock directory and the per-issue ones — hangs off ${HOME}/.the-desk, so
    // pinning it here is what keeps two cases of a parallel run out of each
    // other's state, and any case at all out of the developer's real registry.
    $process = new Process(
        ['bash', '-c', 'exec </dev/null; WORKTREE_LIB=1 . '.escapeshellarg($script).'; '.$snippet],
        $cwd,
        ['HOME' => $cwd.'/home'],
    );
    $process->run();

    return $process;
}

/**
 * Why a run failed, in the terms bin/worktree reports it: every diagnostic it
 * has goes to stderr, and asserting on the exit code alone throws all of it
 * away — "Failed asserting that 1 is identical to 0" is what left issue #1073
 * undiagnosable for as long as it was.
 */
function worktreeFailure(Process $process): string
{
    return sprintf("bin/worktree exited %s; its stderr was:\n%s", $process->getExitCode(), $process->getErrorOutput());
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
 * with $probeExit, exits 100 (apt's own failure code) for any invocation whose
 * arguments contain $failing, and succeeds for everything else.
 *
 * @return array{0: string, 1: string} the fake worktree path and its call log
 */
function fakeSailWorktree(int $probeExit, string $failing = ''): array
{
    $path = tempGitDir('worktree-sail');
    mkdir($path.'/vendor/bin', 0o755, true);

    $failClause = $failing === '' ? ':' : 'case "$*" in *'.$failing.'*) exit 100 ;; esac';

    $log = $path.'/sail-calls.log';
    file_put_contents($path.'/vendor/bin/sail', <<<BASH
        #!/usr/bin/env bash
        printf '%s\n' "\$*" >> {$log}
        [ "\$1" = "shell" ] && exit {$probeExit}
        {$failClause}
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

/**
 * A ./vendor/bin/sail that echoes its own argv on STDOUT, the way the Composer,
 * npm and artisan steps of a real bootstrap do. fakeSailWorktree's stub keeps its
 * record in a file instead, which is what makes it useless for telling apart the
 * two streams.
 */
function writeNoisySail(string $path): void
{
    mkdir($path.'/vendor/bin', 0o755, true);
    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
        #!/usr/bin/env bash
        printf 'sail %s: fetching packages, building, seeding...\n' "$*"
        exit 0
        BASH);
    chmod($path.'/vendor/bin/sail', 0o755);
}

/**
 * An "upstream" whose master already carries what a bootstrap expects to find in
 * the worktree it checks out — an .env to copy and an executable, noisy
 * ./vendor/bin/sail — cloned into a "main checkout". A committed sail is what
 * lets cmd_create run to completion without Docker: its bootstrap only reaches
 * for the throwaway Composer image when ./vendor/bin/sail is missing.
 *
 * @return array{0: string, 1: string} the clone path and its parent directory
 */
function worktreeBootstrapFixture(): array
{
    $root = realpath(sys_get_temp_dir()).'/worktree-bootstrap-'.bin2hex(random_bytes(6));
    mkdir($root.'/upstream', 0o755, true);

    file_put_contents($root.'/upstream/.env', "APP_NAME=Desk\nAPP_KEY=\nAPP_PORT=80\n");
    writeNoisySail($root.'/upstream');

    runGit($root.'/upstream', 'init', '--quiet', '--initial-branch=master', '.');
    runGit($root.'/upstream', 'add', '-A', '-f');
    runGit($root.'/upstream', 'commit', '--quiet', '-m', 'init');

    runGit($root, 'clone', '--quiet', $root.'/upstream', 'main');

    return [$root.'/main', $root];
}

/**
 * A `jq` that shadows the real binary, answering the filters a subcommand
 * reaches with $answers.
 *
 * The drain is load-bearing, not hygiene. Real jq consumes its stdin, and a
 * stub that returns without doing so leaves bin/worktree's
 * `printf '%s' "$entry" | jq -r '.path'` racing its own pipe: when the function
 * wins, printf is left writing into a reader that is already gone. PHP ignores
 * SIGPIPE and the bash it starts inherits that disposition, so the write
 * reports EPIPE instead of dying by signal — and `set -o pipefail` plus
 * `set -e` then abort the script before it has logged a single line. That is
 * the whole of issue #1073: a re-entry that exits 1 under the load of a
 * parallel run and passes every time the file runs alone.
 */
function jqStub(string $answers): string
{
    return "jq() {\n    cat >/dev/null\n".$answers."\n}";
}

/**
 * The bootstrap dependencies this suite's container does not have, defined as
 * shell functions so they shadow the binaries bin/worktree would otherwise call:
 * `require_tooling` aborts on the missing docker, jq backs the registry, and gh
 * supplies the title the branch slug is derived from. The jq stub answers the
 * three filters a first-time `create` reaches — nothing registered for the issue
 * yet, slot 0 free, and the entry it then stores.
 */
function worktreeCreateStubs(): string
{
    $jq = jqStub(<<<'BASH'
            case "$*" in
                *'// empty'*) return 0 ;;
                *'any('*) return 1 ;;
                *) printf '{}\n' ;;
            esac
        BASH);

    return <<<BASH
        require_tooling() { :; }
        gh() { printf 'fix the bootstrap\n'; }
        {$jq}
        BASH;
}

/**
 * A `jq` answering the registry filters a teardown reaches: the entry registered
 * for the issue (nothing, when $project is null), the two fields bin/worktree
 * then reads off it, and the projects every entry claims. The arms are ordered
 * most specific first — `map(.project)` also contains `.project`.
 *
 * @param  list<string>  $registeredProjects
 */
function registryJqStub(?string $path, ?string $project, array $registeredProjects = []): string
{
    $entry = $project === null ? '' : sprintf('{"path":"%s","project":"%s"}', $path, $project);
    $projects = implode('\n', $registeredProjects);

    return jqStub(<<<BASH
            case "\$*" in
                *'map(.project)'*) printf '%b\n' '{$projects}' ;;
                *'// empty'*) printf '%s\n' '{$entry}' ;;
                *'.path'*) printf '%s\n' '{$path}' ;;
                *'.project'*) printf '%s\n' '{$project}' ;;
                *) printf '{}\n' ;;
            esac
        BASH);
}

/**
 * Seed the docker stub's inventory: one `<project> <name>` line per resource.
 *
 * @param  array<string, list<string>>  $inventory
 */
function inventoryLines(array $inventory): string
{
    $lines = '';

    foreach ($inventory as $project => $names) {
        foreach ($names as $name) {
            $lines .= $project.' '.$name."\n";
        }
    }

    return $lines;
}

/**
 * A `docker` that shadows the real binary against a fake inventory rather than a
 * daemon, so a teardown can be driven without one. $volumes and $containers seed
 * what is on disk per Compose project; the stub then reads and writes that
 * inventory the way the real commands would, which is what lets a test assert on
 * what SURVIVED a teardown rather than on which commands it happened to run.
 *
 * The failure shapes issue #1095 turned on are configurable: $stuck names volumes
 * `docker volume rm` refuses, $composeDownFails makes `docker compose down` exit
 * non-zero with a message of its own, and $volumeQueryFails stands in for a
 * daemon that has stopped answering at all.
 *
 * @param  array<string, list<string>>  $volumes  project => volume names on disk
 * @param  array<string, list<string>>  $containers  project => container ids on disk
 * @param  list<string>  $stuck  volumes `docker volume rm` refuses to remove
 */
function dockerStub(
    string $path,
    array $volumes = [],
    array $containers = [],
    array $stuck = [],
    bool $composeDownFails = false,
    bool $volumeQueryFails = false,
): string {
    file_put_contents($path.'/docker-volumes', inventoryLines($volumes));
    file_put_contents($path.'/docker-containers', inventoryLines($containers));

    $stuckList = implode(' ', $stuck);
    $downFails = $composeDownFails ? '1' : '';
    $queryFails = $volumeQueryFails ? '1' : '';

    return <<<BASH
        __VOLUMES__='{$path}/docker-volumes'
        __CONTAINERS__='{$path}/docker-containers'
        __DOCKER_LOG__='{$path}/docker-calls.log'
        __STUCK__='{$stuckList}'
        __DOWN_FAILS__='{$downFails}'
        __QUERY_FAILS__='{$queryFails}'

        __drop_lines() {
            grep -v "\$2" "\$1" > "\$1.tmp" || true
            mv "\$1.tmp" "\$1"
        }

        docker() {
            printf '%s\n' "\$*" >> "\$__DOCKER_LOG__"
            case "\$*" in
                *' down --volumes'*)
                    if [ -n "\$__DOWN_FAILS__" ]; then
                        printf 'error during connect: dial unix docker.raw.sock: EOF\n' >&2
                        return 1
                    fi
                    __drop_lines "\$__VOLUMES__" "^\$3 "
                    __drop_lines "\$__CONTAINERS__" "^\$3 "
                    ;;
                'volume ls --quiet --filter label=com.docker.compose.project='*)
                    if [ -n "\$__QUERY_FAILS__" ]; then
                        printf 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock\n' >&2
                        return 1
                    fi
                    awk -v p="\${5##*=}" '\$1 == p { print \$2 }' "\$__VOLUMES__"
                    ;;
                'ps --all --quiet --filter label=com.docker.compose.project='*)
                    awk -v p="\${5##*=}" '\$1 == p { print \$2 }' "\$__CONTAINERS__"
                    ;;
                'volume ls --filter label=com.docker.compose.project --format '*)
                    awk '{ print \$1 }' "\$__VOLUMES__"
                    ;;
                'ps --all --filter label=com.docker.compose.project --format '*)
                    awk '{ print \$1 }' "\$__CONTAINERS__"
                    ;;
                'volume rm '*)
                    shift 2
                    for __volume in "\$@"; do
                        case " \$__STUCK__ " in *" \$__volume "*) continue ;; esac
                        __drop_lines "\$__VOLUMES__" " \$__volume\\\$"
                    done
                    ;;
                'rm --force --volumes '*)
                    shift 3
                    for __container in "\$@"; do
                        __drop_lines "\$__CONTAINERS__" " \$__container\\\$"
                    done
                    ;;
            esac
            return 0
        }
        BASH;
}

/**
 * The docker stub's recorded invocations, one per line.
 *
 * @return list<string>
 */
function dockerCalls(string $path): array
{
    return sailCalls($path.'/docker-calls.log');
}

/**
 * What the docker stub's inventory still holds, as one `<project> <name>` line
 * per resource.
 */
function dockerInventory(string $path, string $kind = 'volumes'): string
{
    return (string) file_get_contents($path.'/docker-'.$kind);
}

function gitRevision(string $cwd, string $revision): string
{
    return trim(runGit($cwd, 'rev-parse', $revision)->getOutput());
}

/**
 * `.env.example` documents the local dev stack as commented-out assignments, so
 * uncommenting every `# KEY=value` line reconstructs the maximal main-checkout
 * `.env` a worktree could inherit — including the SSO block that made #680.
 */
function maximalEnvExample(): string
{
    $lines = file(dirname(__DIR__, 2).'/.env.example', FILE_IGNORE_NEW_LINES);

    return implode("\n", array_map(
        static fn (string $line): string => (string) preg_replace('/^# ([A-Z][A-Z0-9_]*=)/', '$1', $line),
        $lines === false ? [] : $lines,
    ))."\n";
}

/**
 * The hostname an env value points at, or null when it names no host: values are
 * matched against compose service names, so `http://meilisearch:7700` and a bare
 * `mailpit` both have to reduce to the service they dial.
 */
function envValueHost(string $value): ?string
{
    $value = trim(trim(trim($value), '"\''));
    if (str_contains($value, '://')) {
        $value = substr($value, strpos($value, '://') + 3);
    }
    $host = strtok($value, ':/');

    return $host === false || $host === '' ? null : $host;
}

/**
 * Every `KEY=VALUE` line in $file whose value dials one of $hosts, as
 * `KEY=VALUE` strings so a failure names the offending assignment.
 *
 * @param  list<string>  $hosts
 * @return list<string>
 */
function envAssignmentsDialing(string $file, array $hosts): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_values(array_filter($lines, static function (string $line) use ($hosts): bool {
        if (str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
            return false;
        }

        return in_array(envValueHost(substr($line, strpos($line, '=') + 1)), $hosts, true);
    }));
}

/**
 * The compose services bin/worktree never brings up in a worktree: every service
 * in compose.yaml minus the ones its WORKTREE_STARTED_SERVICES list names.
 *
 * @return list<string>
 */
function unstartedComposeServices(): array
{
    $root = dirname(__DIR__, 2);
    /** @var array{services: array<string, mixed>} $compose */
    $compose = Yaml::parseFile($root.'/compose.yaml');

    $started = preg_split('/\s+/', trim(
        runWorktreeLib(tempGitDir('worktree-services'), 'printf %s "$WORKTREE_STARTED_SERVICES"')->getOutput()
    ), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_diff(array_keys($compose['services']), $started));
}

test('a base branch that exists only on the remote still forks the issue branch', function (): void {
    [$clone, $root] = worktreeFixtureClone();

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
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

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
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

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'epic'));
});

test('HEAD as a base forks from the local checkout, not origin/HEAD', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    file_put_contents($clone.'/README.md', "local only\n");
    runGit($clone, 'commit', '--quiet', '-am', 'local only');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug HEAD');

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'master'))
        ->and(gitRevision($root.'/wt', 'HEAD'))->not->toBe(gitRevision($clone, 'origin/master'));
});

test('an existing local branch is attached instead of being re-forked', function (): void {
    [$clone, $root] = worktreeFixtureClone();
    runGit($clone, 'branch', '619-slug', 'master');

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug develop');

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(trim(runGit($root.'/wt', 'rev-parse', '--abbrev-ref', 'HEAD')->getOutput()))->toBe('619-slug')
        ->and(gitRevision($root.'/wt', 'HEAD'))->toBe(gitRevision($clone, 'master'))
        ->and(gitRevision($root.'/wt', 'HEAD'))->not->toBe(gitRevision($clone, 'origin/develop'));
});

test('a base that names the remote-tracking ref outright is honoured', function (): void {
    [$clone, $root] = worktreeFixtureClone();

    $process = runWorktreeLib($clone, 'attach_worktree '.escapeshellarg($root.'/wt').' 619-slug origin/develop');

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
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

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(sailCalls($log))->toContain('root-shell -c npx playwright install-deps chromium')
        ->and(sailCalls($log))->toContain('npx playwright install chromium');
});

test('the Playwright dependency install parks third-party apt sources around itself', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 1);

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));
    $calls = sailCalls($log);
    $install = array_search('root-shell -c npx playwright install-deps chromium', $calls, true);

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($install)->toBeInt()
        ->and($calls[$install - 1])->toContain('/etc/apt/sources.list.d')
        ->and($calls[$install + 1])->toContain('/etc/apt/sources.list.d');
});

test('an unreachable apt mirror degrades the Playwright step instead of aborting the bootstrap', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 1, failing: 'install-deps');

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));
    $calls = sailCalls($log);
    $install = array_search('root-shell -c npx playwright install-deps chromium', $calls, true);

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getErrorOutput())->toContain('tests/Browser')
        ->and($calls[$install + 1])->toContain('/etc/apt/sources.list.d')
        ->and($calls)->toContain('npx playwright install chromium');
});

test('a worktree that already has chromium skips the Playwright install', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 0);
    touch($path.'/.worktree-playwright-deps');

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(sailCalls($log))->not->toContain('root-shell -c npx playwright install-deps chromium')
        ->and(sailCalls($log))->not->toContain('npx playwright install chromium');
});

test('a degraded system-dependency install is retried even once chromium is downloaded', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 0);

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(sailCalls($log))->toContain('root-shell -c npx playwright install-deps chromium')
        ->and(sailCalls($log))->not->toContain('npx playwright install chromium')
        ->and(is_file($path.'/.worktree-playwright-deps'))->toBeTrue();
});

test('a failed system-dependency install leaves no sentinel behind to skip the retry', function (): void {
    [$path] = fakeSailWorktree(probeExit: 0, failing: 'install-deps');

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(is_file($path.'/.worktree-playwright-deps'))->toBeFalse();
});

test('a fresh worktree migrates and seeds so the demo account can sign in', function (): void {
    [$path] = fakeSailWorktree(probeExit: 0);
    $log = $path.'/sail-calls.log';

    $process = runWorktreeLib($path, 'migrate_and_seed '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(sailCalls($log))->toContain('artisan migrate:fresh --seed --force')
        ->and(is_file($path.'/.worktree-seeded'))->toBeTrue();
});

test('an unseeded worktree is seeded onto a rebuilt schema, never onto a half-seeded one', function (): void {
    [$path] = fakeSailWorktree(probeExit: 0);
    $log = $path.'/sail-calls.log';

    $process = runWorktreeLib($path, 'migrate_and_seed '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(sailCalls($log))->not->toContain('artisan migrate --force')
        ->and(sailCalls($log))->not->toContain('artisan db:seed --force');
});

test('re-entering an already seeded worktree migrates but does not re-seed', function (): void {
    [$path] = fakeSailWorktree(probeExit: 0);
    $log = $path.'/sail-calls.log';
    touch($path.'/.worktree-seeded');

    $process = runWorktreeLib($path, 'migrate_and_seed '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(sailCalls($log))->toContain('artisan migrate --force')
        ->and(sailCalls($log))->not->toContain('artisan db:seed --force')
        ->and(sailCalls($log))->not->toContain('artisan migrate:fresh --seed --force');
});

test('the generated override rebinds Reverb to the worktree host port and keeps the container on 8080', function (): void {
    $path = tempGitDir('worktree-override');

    $process = runWorktreeLib($path, 'write_override '.escapeshellarg($path).' 579 20002');
    $override = (string) file_get_contents($path.'/compose.override.yaml');

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($override)->toContain("'20002:8080'")
        ->and($override)->toContain('ports: !override');
});

test('the trimmed override still brings redis up, so the bootstrap seed can reach the cache', function (): void {
    $path = tempGitDir('worktree-override-redis');

    $process = runWorktreeLib($path, 'write_override '.escapeshellarg($path).' 723 20002');
    /** @var array<string, array<string, array<string, mixed>>> $override */
    $override = Yaml::parseFile($path.'/compose.override.yaml', Yaml::PARSE_CUSTOM_TAGS);
    $dependsOn = $override['services']['laravel.test']['depends_on'];

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($dependsOn)->toBeInstanceOf(TaggedValue::class)
        ->and($dependsOn->getTag())->toBe('override')
        ->and($dependsOn->getValue())->toEqualCanonicalizing(['pgsql', 'redis']);
});

test('the generated env pins the container-internal Reverb port while offsetting the host ports', function (): void {
    $path = tempGitDir('worktree-env');
    file_put_contents($path.'/source.env', "APP_NAME=Desk\nAPP_PORT=80\nREVERB_PORT=443\nDEMO_MODE=true\n");

    $process = runWorktreeLib(
        $path,
        'write_env '.escapeshellarg($path).' '.escapeshellarg($path.'/source.env').' 20000 20001 20003 20004 desk-579',
    );
    $env = (string) file_get_contents($path.'/.env');

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($env)->toContain("\nREVERB_PORT=8080\n")
        ->and($env)->toContain("\nAPP_PORT=20000\n")
        ->and($env)->toContain("\nVITE_PORT=20001\n")
        ->and($env)->toContain("\nFORWARD_DB_PORT=20003\n")
        ->and($env)->toContain("\nFORWARD_REDIS_PORT=20004\n")
        ->and($env)->toContain("\nCOMPOSE_PROJECT_NAME=desk-579\n")
        ->and($env)->toContain("\nAPP_URL=http://localhost:20000\n")
        ->and($env)->toContain("\nDEMO_MODE=false\n");
});

test('the generated env disables the SSO stack the trimmed override never starts', function (): void {
    $path = tempGitDir('worktree-env-sso');
    file_put_contents($path.'/source.env', maximalEnvExample());

    $process = runWorktreeLib(
        $path,
        'write_env '.escapeshellarg($path).' '.escapeshellarg($path.'/source.env').' 20000 20001 20003 20004 desk-680',
    );
    $env = (string) file_get_contents($path.'/.env');

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($env)->toContain("\nLDAP_HOST=\n")
        ->and($env)->toContain("\nSSO_OIDC_ISSUER=\n")
        ->and($env)->toContain("\nSSO_OIDC_CLIENT_ID=\n")
        ->and($env)->toContain("\nCOMPOSE_PROFILES=\n")
        ->and($env)->not->toContain('LDAP_HOST=ldap');
});

test('the generated env routes mail and search away from the containers that stay down', function (): void {
    $path = tempGitDir('worktree-env-drivers');
    file_put_contents($path.'/source.env', maximalEnvExample());

    $process = runWorktreeLib(
        $path,
        'write_env '.escapeshellarg($path).' '.escapeshellarg($path.'/source.env').' 20000 20001 20003 20004 desk-680',
    );
    $env = (string) file_get_contents($path.'/.env');

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($env)->toContain("\nMAIL_MAILER=log\n")
        ->and($env)->toContain("\nSCOUT_DRIVER=collection\n")
        ->and($env)->toContain("\nMEILISEARCH_HOST=\n")
        ->and($env)->toContain("\nMAIL_HOST=\n");
});

test('no generated env value dials a compose service the bootstrap leaves down', function (): void {
    $path = tempGitDir('worktree-env-services');
    file_put_contents($path.'/source.env', maximalEnvExample());

    runWorktreeLib(
        $path,
        'write_env '.escapeshellarg($path).' '.escapeshellarg($path.'/source.env').' 20000 20001 20003 20004 desk-680',
    );

    expect(envAssignmentsDialing($path.'/.env', unstartedComposeServices()))->toBe([]);
});

test('a fresh bootstrap prints nothing but the worktree path on stdout', function (): void {
    [$clone, $root] = worktreeBootstrapFixture();

    $process = runWorktreeLib(
        $clone,
        worktreeCreateStubs()."\nmain create 1043 master",
    );

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getOutput())->toBe($root."/the-desk-worktrees/1043-fix-the-bootstrap\n")
        ->and(is_file($root.'/the-desk-worktrees/1043-fix-the-bootstrap/.worktree-ready'))->toBeTrue();
});

test('a fresh bootstrap still shows every step it runs, on stderr', function (): void {
    [$clone, $root] = worktreeBootstrapFixture();

    $process = runWorktreeLib(
        $clone,
        worktreeCreateStubs()."\nmain create 1043 master",
    );

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getErrorOutput())->toContain('composer install --no-interaction')
        ->and($process->getErrorOutput())->toContain('npm run build')
        ->and($process->getErrorOutput())->toContain('worktree ready');
});

test('re-entering a ready worktree prints nothing but its path either', function (): void {
    $path = tempGitDir('worktree-reentry');
    writeNoisySail($path);
    touch($path.'/.worktree-ready');

    $jq = jqStub(<<<BASH
            case "\$*" in
                *'// empty'*) printf '%s\n' '{"path":"{$path}"}' ;;
                *) printf '%s\n' '{$path}' ;;
            esac
        BASH);

    $stubs = "require_tooling() { :; }\n".$jq;

    $process = runWorktreeLib($path, $stubs."\nmain create 1043");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getOutput())->toBe($path."\n")
        ->and($process->getErrorOutput())->toContain('playwright install-deps chromium');
});

test('the stubbed jq drains the pipe bin/worktree writes the registry entry into', function (): void {
    $path = tempGitDir('worktree-jq-stub');

    // The race the re-entry case above lost intermittently under a parallel run
    // (issue #1073), made deterministic: 200 KB overruns the pipe buffer, so a
    // stub that returns without reading leaves the writer holding bytes nothing
    // will ever consume. SIGPIPE is ignored the way PHP leaves it for the bash
    // it starts, which is what turns the dead pipe into a non-zero exit rather
    // than a signal — and pipefail then takes the whole run down.
    $jq = jqStub("    printf '{}'");

    $process = runWorktreeLib($path, <<<BASH
        {$jq}
        trap '' PIPE
        printf '%*s' 200000 '' | jq -r '.path' >/dev/null
        BASH);

    expect($process->getExitCode())->toBe(0, worktreeFailure($process));
});

test('list keeps its machine-readable table on stdout', function (): void {
    $path = tempGitDir('worktree-list');

    $jq = jqStub(<<<'BASH'
            case "$*" in
                *length*) printf '1\n' ;;
                *) printf '1043\t0\t20000\t20001\t20002\t20003\t1043-slug\t/wt/1043-slug\n' ;;
            esac
        BASH);

    $stubs = "require_tooling() { :; }\n".$jq;

    $process = runWorktreeLib($path, $stubs."\nmain list");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getOutput())->toContain('ISSUE')
        ->and($process->getOutput())->toContain('1043-slug')
        ->and($process->getOutput())->toContain('/wt/1043-slug');
});

test('remove reclaims every volume the project still holds', function (): void {
    $path = tempGitDir('worktree-remove');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub($path.'/wt', 'desk-377')."\n"
        .dockerStub($path, volumes: ['desk-377' => ['desk-377_sail-pgsql', 'desk-377_sail-redis']]);

    $process = runWorktreeLib($path, $stubs."\nmain remove 377");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(dockerInventory($path))->toBe('');
});

// The teardown that shipped swallowed compose's stderr and logged "already
// down?" on ANY non-zero exit, then deleted the registry entry that was the only
// way back to the volumes — so the failure was indistinguishable from a clean
// run right up until the disk filled (issue #1095).
test('a failed compose teardown is reported with its own error, not as "already down?"', function (): void {
    $path = tempGitDir('worktree-remove-compose-failure');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub($path.'/wt', 'desk-377')."\n"
        .dockerStub($path, volumes: ['desk-377' => ['desk-377_sail-pgsql']], composeDownFails: true);

    $process = runWorktreeLib($path, $stubs."\nmain remove 377");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getErrorOutput())->toContain('dial unix docker.raw.sock')
        ->and($process->getErrorOutput())->not->toContain('already down?')
        ->and(dockerInventory($path))->toBe('');
});

test('remove names the volumes it could not reclaim and fails rather than reporting success', function (): void {
    $path = tempGitDir('worktree-remove-stuck');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub($path.'/wt', 'desk-377')."\n"
        .dockerStub(
            $path,
            volumes: ['desk-377' => ['desk-377_sail-pgsql']],
            stuck: ['desk-377_sail-pgsql'],
            composeDownFails: true,
        );

    $process = runWorktreeLib($path, $stubs."\nmain remove 377");

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('desk-377_sail-pgsql')
        ->and($process->getErrorOutput())->toContain('reap');
});

// A daemon that has stopped answering says nothing about the disk, and reading
// that silence as "nothing left" would report exactly the clean teardown this
// change exists to stop reporting.
test('remove treats an unanswerable Docker as a failed teardown, not an empty one', function (): void {
    $path = tempGitDir('worktree-remove-unanswerable');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub($path.'/wt', 'desk-377')."\n"
        .dockerStub($path, volumes: ['desk-377' => ['desk-377_sail-pgsql']], volumeQueryFails: true);

    $process = runWorktreeLib($path, $stubs."\nmain remove 377");

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('Cannot connect to the Docker daemon')
        ->and($process->getErrorOutput())->toContain('reap');
});

// A worktree removed by hand with `git worktree remove`, or one whose registry
// entry a failed teardown deleted, left the volumes with no supported way to
// reclaim them at all: `remove` refused to run without an entry. The project
// name is derivable from the issue number, so the entry is a convenience.
test('remove falls back to the derivable project when no registry entry survives', function (): void {
    $path = tempGitDir('worktree-remove-unregistered');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub(null, null)."\n"
        .dockerStub($path, volumes: ['desk-377' => ['desk-377_sail-pgsql']]);

    $process = runWorktreeLib($path, $stubs."\nmain remove 377");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getErrorOutput())->toContain('desk-377')
        ->and(dockerInventory($path))->toBe('');
});

// Anonymous volumes are only reachable through the container that mounts them,
// so a container compose left behind holds disk nothing else can name.
test('remove takes containers compose left behind with it, and their anonymous volumes', function (): void {
    $path = tempGitDir('worktree-remove-containers');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub($path.'/wt', 'desk-377')."\n"
        .dockerStub(
            $path,
            volumes: ['desk-377' => ['desk-377_sail-pgsql']],
            containers: ['desk-377' => ['c0ffee']],
            composeDownFails: true,
        );

    $process = runWorktreeLib($path, $stubs."\nmain remove 377");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(dockerCalls($path))->toContain('rm --force --volumes c0ffee')
        ->and(dockerInventory($path, 'containers'))->toBe('');
});

test('reap reclaims orphaned desk projects and leaves registered ones alone', function (): void {
    $path = tempGitDir('worktree-reap');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub(null, null, ['desk-529'])."\n"
        .dockerStub($path, volumes: [
            'desk-377' => ['desk-377_sail-pgsql'],
            'desk-529' => ['desk-529_sail-pgsql'],
            'the-desk' => ['the-desk_sail-pgsql'],
        ]);

    $process = runWorktreeLib($path, $stubs."\nmain reap");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and(dockerInventory($path))->not->toContain('desk-377_sail-pgsql')
        ->and(dockerInventory($path))->toContain('desk-529_sail-pgsql')
        ->and(dockerInventory($path))->toContain('the-desk_sail-pgsql');
});

test('reap --dry-run names what it would reclaim without removing any of it', function (): void {
    $path = tempGitDir('worktree-reap-dry-run');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub(null, null)."\n"
        .dockerStub($path, volumes: ['desk-377' => ['desk-377_sail-pgsql']]);

    $process = runWorktreeLib($path, $stubs."\nmain reap --dry-run");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getErrorOutput())->toContain('desk-377_sail-pgsql')
        ->and(dockerInventory($path))->toContain('desk-377_sail-pgsql');
});

// Surfaced where the operator already looks, and on stderr, so `list` keeps the
// machine-readable table it is contracted to put on stdout.
test('list warns about orphaned projects on stderr while its table stays on stdout', function (): void {
    $path = tempGitDir('worktree-list-orphans');

    $jq = jqStub(<<<'BASH'
            case "$*" in
                *'map(.project)'*) printf 'desk-529\n' ;;
                *length*) printf '1\n' ;;
                *) printf '1043\t0\t20000\t20001\t20002\t20003\t1043-slug\t/wt/1043-slug\n' ;;
            esac
        BASH);

    $stubs = "require_tooling() { :; }\n".$jq."\n"
        .dockerStub($path, volumes: [
            'desk-377' => ['desk-377_sail-pgsql'],
            'desk-529' => ['desk-529_sail-pgsql'],
        ]);

    $process = runWorktreeLib($path, $stubs."\nmain list");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getOutput())->toContain('1043-slug')
        ->and($process->getOutput())->not->toContain('desk-377')
        ->and($process->getErrorOutput())->toContain('desk-377')
        ->and($process->getErrorOutput())->toContain('reap')
        ->and($process->getErrorOutput())->not->toContain('desk-529');
});

// The orphan scan is a snapshot and reap deletes data, so the registry is read
// again under the same per-issue lock `create` holds. Here the scan sees no
// registered project (`map(.project)` is empty) while the re-check finds the
// entry a concurrent bootstrap wrote in between — the shape of the race.
test('reap skips a project a concurrent bootstrap registered while it was scanning', function (): void {
    $path = tempGitDir('worktree-reap-race');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub($path.'/wt', 'desk-377')."\n"
        .dockerStub($path, volumes: ['desk-377' => ['desk-377_sail-pgsql']]);

    $process = runWorktreeLib($path, $stubs."\nmain reap");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getErrorOutput())->toContain('skipping desk-377')
        ->and(dockerInventory($path))->toContain('desk-377_sail-pgsql');
});

// Nothing to reap is the ordinary case, and it must not read as a problem.
test('reap says so plainly when no desk project has been orphaned', function (): void {
    $path = tempGitDir('worktree-reap-clean');
    $stubs = "require_tooling() { :; }\n"
        .registryJqStub(null, null, ['desk-529'])."\n"
        .dockerStub($path, volumes: ['desk-529' => ['desk-529_sail-pgsql']]);

    $process = runWorktreeLib($path, $stubs."\nmain reap");

    expect($process->getExitCode())->toBe(0, worktreeFailure($process))
        ->and($process->getErrorOutput())->toContain('no orphaned');
});

test('the services the bootstrap starts all exist in compose.yaml', function (): void {
    /** @var array{services: array<string, mixed>} $compose */
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');

    expect(array_values(array_diff(array_keys($compose['services']), unstartedComposeServices())))
        ->toEqualCanonicalizing(['laravel.test', 'pgsql', 'reverb', 'redis']);
});
