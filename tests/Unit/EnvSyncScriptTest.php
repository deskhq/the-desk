<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Drive docker/env-sync.sh as an operator would: a plain POSIX shell process
 * against fixture files. Exit codes follow diff semantics: 0 = in sync,
 * 1 = missing keys found, 2 = usage or file errors.
 */
function runEnvSync(string ...$arguments): Process
{
    $process = new Process(['sh', dirname(__DIR__, 2).'/docker/env-sync.sh', ...$arguments]);
    $process->run();

    return $process;
}

function envSyncFixture(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'env-sync-test-');
    file_put_contents($path, $contents);

    return $path;
}

test('report mode lists active template keys missing from the env file and exits 1', function (): void {
    $env = envSyncFixture("APP_NAME=\"My Desk\"\n");
    $template = envSyncFixture(<<<'EOT'
        APP_NAME="The Desk"

        # --- Feature toggles ---
        NEW_TOGGLE=true
        NEW_URL=
        EOT."\n");

    $process = runEnvSync($env, $template);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('NEW_TOGGLE')
        ->and($process->getOutput())->toContain('NEW_URL')
        ->and($process->getOutput())->not->toContain('APP_NAME');
});

test('commented-out template keys are not treated as missing', function (): void {
    $env = envSyncFixture("APP_NAME=\"My Desk\"\n");
    $template = envSyncFixture(<<<'EOT'
        APP_NAME="The Desk"
        # APP_PORT=8000
        #SSO_OIDC_ISSUER=https://idp.example.com
        EOT."\n");

    $process = runEnvSync($env, $template);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->not->toContain('APP_PORT')
        ->and($process->getOutput())->not->toContain('SSO_OIDC_ISSUER');
});

test('an env file already carrying every active template key reports nothing and exits 0', function (): void {
    $env = envSyncFixture("APP_NAME=\"My Desk\"\nNEW_TOGGLE=false\n");
    $template = envSyncFixture("APP_NAME=\"The Desk\"\nNEW_TOGGLE=true\n");

    $process = runEnvSync($env, $template);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe('');
});

test('--apply appends missing keys with their template comment blocks and never touches existing keys', function (): void {
    $env = envSyncFixture("APP_NAME=\"My Desk\"\nEXISTING=keep\n");
    $template = envSyncFixture(<<<'EOT'
        APP_NAME="The Desk"

        # --- Feature toggles ---
        # Turn the new thing on or off.
        NEW_TOGGLE=true
        # NEW_TOGGLE_URL=https://example.com
        NEW_URL=
        EOT."\n");

    $process = runEnvSync($env, $template, '--apply');
    $result = file_get_contents($env);

    expect($process->getExitCode())->toBe(0)
        ->and($result)->toStartWith("APP_NAME=\"My Desk\"\nEXISTING=keep\n")
        ->and($result)->toContain("# Turn the new thing on or off.\nNEW_TOGGLE=true\n")
        ->and($result)->toContain("NEW_URL=\n")
        ->and($result)->not->toContain('NEW_TOGGLE_URL')
        ->and($result)->not->toContain('APP_NAME="The Desk"')
        ->and(substr_count($result, 'APP_NAME='))->toBe(1);

    $recheck = runEnvSync($env, $template);

    expect($recheck->getExitCode())->toBe(0);
});

test('usage and file errors exit 2', function (array $arguments): void {
    $process = runEnvSync(...$arguments);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->not->toBe('');
})->with([
    'no arguments' => [[]],
    'one argument' => [['only-one']],
    'unknown option' => [['a', 'b', '--frobnicate']],
    'missing files' => [['/nonexistent/.env', '/nonexistent/.env.prod.example']],
    'extra argument' => [['a', 'b', 'c']],
]);

test('a copy of the shipped template is already in sync with the template', function (): void {
    $template = dirname(__DIR__, 2).'/.env.prod.example';
    $env = envSyncFixture(file_get_contents($template));

    $process = runEnvSync($env, $template);

    expect($process->getExitCode())->toBe(0);
});
