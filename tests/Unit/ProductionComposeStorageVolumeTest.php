<?php

declare(strict_types=1);

use Tests\Support\ProductionCompose;

/**
 * `app`, `queue`, `queue-broadcasts`, `reverb`, and `scheduler` all mount the
 * same named volume (`storage-app`). On a fresh volume the daemon seeds it from
 * the image (copy-up); containers created at the same instant race inside
 * dockerd and one dies with `mkdir /var/lib/docker/volumes/..._data/private:
 * file exists`. Making the workers wait for `app` means exactly one container
 * triggers the copy-up. See issue #609.
 */
test('every app-role service mounts the shared storage-app volume', function (string $service): void {
    expect(ProductionCompose::services()[$service]['volumes'])
        ->toContain('storage-app:/app/storage/app');
})->with(fn (): array => ProductionCompose::appRoleServices());

test('the shared storage-app volume is initialised by app alone', function (string $service): void {
    $dependsOn = ProductionCompose::services()[$service]['depends_on'];

    expect($dependsOn)->toHaveKey('app')
        ->and($dependsOn['app']['condition'])->toBe('service_started');
})->with(fn (): array => ProductionCompose::appRoleWorkers());

test('waiting on app does not drop the infrastructure dependencies', function (string $service): void {
    $dependsOn = ProductionCompose::services()[$service]['depends_on'];

    foreach (['pgsql', 'redis', 'meilisearch'] as $dependency) {
        expect($dependsOn[$dependency]['condition'])->toBe('service_healthy');
    }
})->with(fn (): array => ProductionCompose::appRoleServices());

test('app does not depend on itself', function (): void {
    expect(ProductionCompose::services()['app']['depends_on'])->not->toHaveKey('app');
});
