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
/**
 * @param  array<string, string>  $env  extra environment for the run, merged into the inherited one
 */
function runWorktreeLib(string $cwd, string $snippet, array $env = []): Process
{
    $script = dirname(__DIR__, 2).'/bin/worktree';

    $process = new Process(
        ['bash', '-c', 'WORKTREE_LIB=1 . '.escapeshellarg($script).'; '.$snippet],
        $cwd,
        $env,
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
 * The bootstrap dependencies this suite's container does not have, defined as
 * shell functions so they shadow the binaries bin/worktree would otherwise call:
 * `require_tooling` aborts on the missing docker, jq backs the registry, and gh
 * supplies the title the branch slug is derived from. The jq stub answers the
 * three filters a first-time `create` reaches — nothing registered for the issue
 * yet, slot 0 free, and the entry it then stores.
 */
function worktreeCreateStubs(): string
{
    return <<<'BASH'
        require_tooling() { :; }
        gh() { printf 'fix the bootstrap\n'; }
        jq() {
            case "$*" in
                *'// empty'*) return 0 ;;
                *'any('*) return 1 ;;
                *) printf '{}\n' ;;
            esac
        }
        BASH;
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

test('the Playwright dependency install parks third-party apt sources around itself', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 1);

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));
    $calls = sailCalls($log);
    $install = array_search('root-shell -c npx playwright install-deps chromium', $calls, true);

    expect($process->getExitCode())->toBe(0)
        ->and($install)->toBeInt()
        ->and($calls[$install - 1])->toContain('/etc/apt/sources.list.d')
        ->and($calls[$install + 1])->toContain('/etc/apt/sources.list.d');
});

test('an unreachable apt mirror degrades the Playwright step instead of aborting the bootstrap', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 1, failing: 'install-deps');

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));
    $calls = sailCalls($log);
    $install = array_search('root-shell -c npx playwright install-deps chromium', $calls, true);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('tests/Browser')
        ->and($calls[$install + 1])->toContain('/etc/apt/sources.list.d')
        ->and($calls)->toContain('npx playwright install chromium');
});

test('a worktree that already has chromium skips the Playwright install', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 0);
    touch($path.'/.worktree-playwright-deps');

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0)
        ->and(sailCalls($log))->not->toContain('root-shell -c npx playwright install-deps chromium')
        ->and(sailCalls($log))->not->toContain('npx playwright install chromium');
});

test('a degraded system-dependency install is retried even once chromium is downloaded', function (): void {
    [$path, $log] = fakeSailWorktree(probeExit: 0);

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0)
        ->and(sailCalls($log))->toContain('root-shell -c npx playwright install-deps chromium')
        ->and(sailCalls($log))->not->toContain('npx playwright install chromium')
        ->and(is_file($path.'/.worktree-playwright-deps'))->toBeTrue();
});

test('a failed system-dependency install leaves no sentinel behind to skip the retry', function (): void {
    [$path] = fakeSailWorktree(probeExit: 0, failing: 'install-deps');

    $process = runWorktreeLib($path, 'install_playwright_browsers '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0)
        ->and(is_file($path.'/.worktree-playwright-deps'))->toBeFalse();
});

test('a fresh worktree migrates and seeds so the demo account can sign in', function (): void {
    [$path] = fakeSailWorktree(probeExit: 0);
    $log = $path.'/sail-calls.log';

    $process = runWorktreeLib($path, 'migrate_and_seed '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0)
        ->and(sailCalls($log))->toContain('artisan migrate:fresh --seed --force')
        ->and(is_file($path.'/.worktree-seeded'))->toBeTrue();
});

test('an unseeded worktree is seeded onto a rebuilt schema, never onto a half-seeded one', function (): void {
    [$path] = fakeSailWorktree(probeExit: 0);
    $log = $path.'/sail-calls.log';

    $process = runWorktreeLib($path, 'migrate_and_seed '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0)
        ->and(sailCalls($log))->not->toContain('artisan migrate --force')
        ->and(sailCalls($log))->not->toContain('artisan db:seed --force');
});

test('re-entering an already seeded worktree migrates but does not re-seed', function (): void {
    [$path] = fakeSailWorktree(probeExit: 0);
    $log = $path.'/sail-calls.log';
    touch($path.'/.worktree-seeded');

    $process = runWorktreeLib($path, 'migrate_and_seed '.escapeshellarg($path));

    expect($process->getExitCode())->toBe(0)
        ->and(sailCalls($log))->toContain('artisan migrate --force')
        ->and(sailCalls($log))->not->toContain('artisan db:seed --force')
        ->and(sailCalls($log))->not->toContain('artisan migrate:fresh --seed --force');
});

test('the generated override rebinds Reverb to the worktree host port and keeps the container on 8080', function (): void {
    $path = tempGitDir('worktree-override');

    $process = runWorktreeLib($path, 'write_override '.escapeshellarg($path).' 579 20002');
    $override = (string) file_get_contents($path.'/compose.override.yaml');

    expect($process->getExitCode())->toBe(0)
        ->and($override)->toContain("'20002:8080'")
        ->and($override)->toContain('ports: !override');
});

test('the trimmed override still brings redis up, so the bootstrap seed can reach the cache', function (): void {
    $path = tempGitDir('worktree-override-redis');

    $process = runWorktreeLib($path, 'write_override '.escapeshellarg($path).' 723 20002');
    /** @var array<string, array<string, array<string, mixed>>> $override */
    $override = Yaml::parseFile($path.'/compose.override.yaml', Yaml::PARSE_CUSTOM_TAGS);
    $dependsOn = $override['services']['laravel.test']['depends_on'];

    expect($process->getExitCode())->toBe(0)
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

test('the generated env disables the SSO stack the trimmed override never starts', function (): void {
    $path = tempGitDir('worktree-env-sso');
    file_put_contents($path.'/source.env', maximalEnvExample());

    $process = runWorktreeLib(
        $path,
        'write_env '.escapeshellarg($path).' '.escapeshellarg($path.'/source.env').' 20000 20001 20003 20004 desk-680',
    );
    $env = (string) file_get_contents($path.'/.env');

    expect($process->getExitCode())->toBe(0)
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

    expect($process->getExitCode())->toBe(0)
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
        ['HOME' => $root.'/home'],
    );

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe($root."/the-desk-worktrees/1043-fix-the-bootstrap\n")
        ->and(is_file($root.'/the-desk-worktrees/1043-fix-the-bootstrap/.worktree-ready'))->toBeTrue();
});

test('a fresh bootstrap still shows every step it runs, on stderr', function (): void {
    [$clone, $root] = worktreeBootstrapFixture();

    $process = runWorktreeLib(
        $clone,
        worktreeCreateStubs()."\nmain create 1043 master",
        ['HOME' => $root.'/home'],
    );

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('composer install --no-interaction')
        ->and($process->getErrorOutput())->toContain('npm run build')
        ->and($process->getErrorOutput())->toContain('worktree ready');
});

test('re-entering a ready worktree prints nothing but its path either', function (): void {
    $path = tempGitDir('worktree-reentry');
    writeNoisySail($path);
    touch($path.'/.worktree-ready');

    $stubs = <<<BASH
        require_tooling() { :; }
        jq() {
            case "\$*" in
                *'// empty'*) printf '%s\n' '{"path":"{$path}"}' ;;
                *) printf '%s\n' '{$path}' ;;
            esac
        }
        BASH;

    $process = runWorktreeLib($path, $stubs."\nmain create 1043", ['HOME' => $path.'/home']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe($path."\n")
        ->and($process->getErrorOutput())->toContain('playwright install-deps chromium');
});

test('list keeps its machine-readable table on stdout', function (): void {
    $path = tempGitDir('worktree-list');

    $stubs = <<<'BASH'
        require_tooling() { :; }
        jq() {
            case "$*" in
                *length*) printf '1\n' ;;
                *) printf '1043\t0\t20000\t20001\t20002\t20003\t1043-slug\t/wt/1043-slug\n' ;;
            esac
        }
        BASH;

    $process = runWorktreeLib($path, $stubs."\nmain list", ['HOME' => $path.'/home']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('ISSUE')
        ->and($process->getOutput())->toContain('1043-slug')
        ->and($process->getOutput())->toContain('/wt/1043-slug');
});

test('the services the bootstrap starts all exist in compose.yaml', function (): void {
    /** @var array{services: array<string, mixed>} $compose */
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');

    expect(array_values(array_diff(array_keys($compose['services']), unstartedComposeServices())))
        ->toEqualCanonicalizing(['laravel.test', 'pgsql', 'reverb', 'redis']);
});
