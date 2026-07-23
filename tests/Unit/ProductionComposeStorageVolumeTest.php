<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * `app`, `queue`, `queue-broadcasts`, `reverb`, and `scheduler` all mount the
 * same named volume (`storage-app`). On a fresh volume the daemon seeds it from
 * the image (copy-up); containers created at the same instant race inside
 * dockerd and one dies with `mkdir /var/lib/docker/volumes/..._data/private:
 * file exists`. Making the workers wait for `app` means exactly one container
 * triggers the copy-up. See issue #609.
 */
$compose = fn (): array => Yaml::parseFile(dirname(__DIR__, 2).'/docker-compose.prod.yml');

$sharedVolumeWorkers = ['queue', 'queue-broadcasts', 'reverb', 'scheduler'];

test('every app-role service mounts the shared storage-app volume', function () use ($compose, $sharedVolumeWorkers): void {
    $services = $compose()['services'];

    foreach (['app', ...$sharedVolumeWorkers] as $service) {
        expect($services[$service]['volumes'])->toContain('storage-app:/app/storage/app');
    }
});

test('the shared storage-app volume is initialised by app alone', function (string $service) use ($compose): void {
    $dependsOn = $compose()['services'][$service]['depends_on'];

    expect($dependsOn)->toHaveKey('app')
        ->and($dependsOn['app']['condition'])->toBe('service_started');
})->with($sharedVolumeWorkers);

test('waiting on app does not drop the infrastructure dependencies', function (string $service) use ($compose): void {
    $dependsOn = $compose()['services'][$service]['depends_on'];

    foreach (['pgsql', 'redis', 'meilisearch'] as $dependency) {
        expect($dependsOn[$dependency]['condition'])->toBe('service_healthy');
    }
})->with([...$sharedVolumeWorkers, 'app']);

test('app does not depend on itself', function () use ($compose): void {
    expect($compose()['services']['app']['depends_on'])->not->toHaveKey('app');
});
