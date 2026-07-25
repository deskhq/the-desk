<?php

declare(strict_types=1);

use Base64Url\Base64Url;
use Illuminate\Filesystem\Filesystem;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\ES256;
use Minishlink\WebPush\VAPID;
use Symfony\Component\Process\Process;

/**
 * Drive docker/gen-secrets.sh as an operator does: a plain POSIX shell process
 * in a directory holding the shipped template, then read back the .env it wrote.
 *
 * The VAPID pair is the one secret the script cannot mint with an `openssl rand`
 * one-liner. It carves the key material out of DER at two fixed offsets
 * (`tail -c 65`, `skip=7`), and a wrong offset still yields a plausible-looking
 * base64url string of the right length — keys that sign nothing. So the pair is
 * checked against the library that has to use it, not by eye. See issue #869.
 *
 * @param  string|null  $pathPrefix  Prepended to PATH, to put a stub openssl in front of the real one.
 */
function runGenSecrets(string $workspace, ?string $pathPrefix = null): Process
{
    $process = new Process(
        ['sh', dirname(__DIR__, 2).'/docker/gen-secrets.sh'],
        $workspace,
        $pathPrefix === null ? [] : ['PATH' => $pathPrefix.':'.getenv('PATH')],
    );
    $process->run();

    return $process;
}

/**
 * Everything this file writes, under one directory per process so parallel
 * workers never clear each other's in-flight fixtures.
 */
function genSecretsRoot(): string
{
    return sys_get_temp_dir().'/gen-secrets-'.getmypid();
}

/**
 * A throwaway directory seeded with the shipped template, which is what the
 * script copies to .env and then fills.
 */
function genSecretsWorkspace(): string
{
    $workspace = genSecretsRoot().'/'.bin2hex(random_bytes(6));
    mkdir($workspace, recursive: true);
    copy(dirname(__DIR__, 2).'/.env.prod.example', $workspace.'/.env.prod.example');

    return $workspace;
}

/**
 * @return array<string, string>
 */
function genSecretsEnv(string $workspace): array
{
    $values = [];

    foreach ((array) file($workspace.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', (string) $line, $matches) === 1) {
            $values[$matches[1]] = $matches[2];
        }
    }

    return $values;
}

/**
 * An openssl on PATH that refuses to generate a P-256 key and delegates
 * everything else, standing in for a host whose openssl lacks the curve.
 */
function stubOpensslWithoutPrime256v1(string $workspace): string
{
    $binary = new Process(['sh', '-c', 'command -v openssl']);
    $binary->mustRun();
    $openssl = trim($binary->getOutput());

    $directory = $workspace.'/stub-bin';
    mkdir($directory);
    file_put_contents($directory.'/openssl', <<<SH
        #!/bin/sh
        if [ "\$1" = "ecparam" ]; then
            echo "ecparam: unknown curve" >&2
            exit 1
        fi
        exec {$openssl} "\$@"
        SH);
    chmod($directory.'/openssl', 0o755);

    return $directory;
}

/**
 * The JWK halves of a generated pair: the private one signs, the public one
 * verifies, so a scalar that does not belong to the point cannot pass both.
 *
 * @param  array<string, string>  $env
 * @return array{private: JWK, public: JWK}
 */
function genSecretsVapidKeyPair(array $env): array
{
    $point = Base64Url::decode($env['VAPID_PUBLIC_KEY']);
    $coordinates = [
        'kty' => 'EC',
        'crv' => 'P-256',
        'x' => Base64Url::encode(substr($point, 1, 32)),
        'y' => Base64Url::encode(substr($point, 33, 32)),
    ];

    return [
        'private' => new JWK([...$coordinates, 'd' => $env['VAPID_PRIVATE_KEY']]),
        'public' => new JWK($coordinates),
    ];
}

afterEach(function (): void {
    (new Filesystem)->deleteDirectory(genSecretsRoot());
});

test('the production template ships the VAPID settings empty, which is what gen-secrets can fill', function (): void {
    $template = (string) file_get_contents(dirname(__DIR__, 2).'/.env.prod.example');

    expect($template)->toMatch('/^VAPID_PUBLIC_KEY=$/m')
        ->and($template)->toMatch('/^VAPID_PRIVATE_KEY=$/m')
        ->and($template)->toMatch('/^VAPID_SUBJECT=$/m');
});

test('a fresh .env comes out with a VAPID pair the web push library accepts', function (): void {
    $workspace = genSecretsWorkspace();

    $process = runGenSecrets($workspace);
    $env = genSecretsEnv($workspace);

    expect($process->getExitCode())->toBe(0)
        ->and(VAPID::validate([
            'subject' => 'mailto:admin@example.com',
            'publicKey' => $env['VAPID_PUBLIC_KEY'],
            'privateKey' => $env['VAPID_PRIVATE_KEY'],
        ]))->toMatchArray(['subject' => 'mailto:admin@example.com']);
});

test('the generated private key is the one that signs for the generated public key', function (): void {
    $workspace = genSecretsWorkspace();
    $otherWorkspace = genSecretsWorkspace();

    runGenSecrets($workspace);
    runGenSecrets($otherWorkspace);

    $pair = genSecretsVapidKeyPair(genSecretsEnv($workspace));
    $otherPair = genSecretsVapidKeyPair(genSecretsEnv($otherWorkspace));

    $algorithm = new ES256;
    $signature = $algorithm->sign($pair['private'], 'the-desk');

    expect($algorithm->verify($pair['public'], 'the-desk', $signature))->toBeTrue()
        // Guard the guard: a check that passed for any pair would prove nothing.
        ->and($algorithm->verify($otherPair['public'], 'the-desk', $signature))->toBeFalse();
});

test('VAPID_SUBJECT is left to the operator, since it is an address and not a secret', function (): void {
    $workspace = genSecretsWorkspace();

    runGenSecrets($workspace);

    expect(genSecretsEnv($workspace)['VAPID_SUBJECT'])->toBe('');
});

test('re-running never rotates the pair, which every granted subscription is pinned to', function (): void {
    $workspace = genSecretsWorkspace();

    runGenSecrets($workspace);
    $first = genSecretsEnv($workspace);

    $process = runGenSecrets($workspace);
    $second = genSecretsEnv($workspace);

    expect($process->getOutput())->toContain('kept VAPID_PUBLIC_KEY')
        ->and($second['VAPID_PUBLIC_KEY'])->toBe($first['VAPID_PUBLIC_KEY'])
        ->and($second['VAPID_PRIVATE_KEY'])->toBe($first['VAPID_PRIVATE_KEY']);
});

test('a half-set pair is reported rather than completed with a key from another one', function (): void {
    $workspace = genSecretsWorkspace();
    runGenSecrets($workspace);
    $generated = genSecretsEnv($workspace);

    file_put_contents($workspace.'/.env', preg_replace(
        '/^VAPID_PRIVATE_KEY=.*$/m',
        'VAPID_PRIVATE_KEY=',
        (string) file_get_contents($workspace.'/.env'),
    ));
    $process = runGenSecrets($workspace);
    $env = genSecretsEnv($workspace);

    expect($process->getErrorOutput())->toContain('only one half of the VAPID pair is set')
        ->and($env['VAPID_PRIVATE_KEY'])->toBe('')
        ->and($env['VAPID_PUBLIC_KEY'])->toBe($generated['VAPID_PUBLIC_KEY']);
});

test('an openssl that cannot do prime256v1 leaves both halves empty rather than writing one', function (): void {
    $workspace = genSecretsWorkspace();

    $process = runGenSecrets($workspace, stubOpensslWithoutPrime256v1($workspace));
    $env = genSecretsEnv($workspace);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toContain('VAPID')
        ->and($env['VAPID_PUBLIC_KEY'])->toBe('')
        ->and($env['VAPID_PRIVATE_KEY'])->toBe('')
        // The rest of the install still gets its secrets; only web push stays off.
        ->and($env['APP_KEY'])->toStartWith('base64:');
});
